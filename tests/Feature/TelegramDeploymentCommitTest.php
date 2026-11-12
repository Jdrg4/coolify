<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Notifications\Application\DeploymentSuccess;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'network' => 'test-network-'.fake()->unique()->word(),
    ]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'name' => 'test-app',
        'fqdn' => 'https://test-app.example.com',
        'git_branch' => 'main',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
});

function telegramDeploymentNotification(Application $application, string $commit, ?string $commitMessage): array
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => 'test-'.fake()->unique()->uuid(),
        'commit' => $commit,
        'commit_message' => $commitMessage,
        'status' => 'finished',
    ]);

    return (new DeploymentSuccess($application, $deployment->deployment_uuid, null, $deployment))->toTelegram();
}

it('shows the short commit hash, branch and subject in the Telegram message', function () {
    $telegram = telegramDeploymentNotification(
        $this->application,
        'abcdef1234567890abcdef1234567890abcdef12',
        "feat(api): add invoices endpoint\n\nThe body of the commit message is not shown.",
    );

    expect($telegram['message'])
        ->toContain('✅ New version successfully deployed of test-app')
        ->toContain('🏷 abcdef1 · main')
        ->toContain('📝 feat(api): add invoices endpoint')
        ->not->toContain('The body of the commit message is not shown');

    expect($telegram['buttons'])->toHaveCount(2);
});

it('keeps the message unchanged when the deployment has no commit details', function () {
    $telegram = telegramDeploymentNotification($this->application, 'HEAD', null);

    expect($telegram['message'])->toBe('✅ New version successfully deployed of test-app');
});

it('truncates a commit subject that is too long for a notification', function () {
    $telegram = telegramDeploymentNotification(
        $this->application,
        'abcdef1234567890abcdef1234567890abcdef12',
        str_repeat('a', 200),
    );

    expect($telegram['message'])
        ->toContain('📝 ')
        ->toContain('…')
        ->not->toContain(str_repeat('a', 200));
});
