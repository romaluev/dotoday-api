<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Enums\TaskPriority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;
    private array $taskData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        
        $this->taskData = [
            'title' => $this->faker->sentence,
            'description' => $this->faker->paragraph,
            'is_completed' => false,
            'priority' => randomElement(TaskPriority::cases()),
            'due_date' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ];
    }

    /** @test */
    public function api_routes_require_authentication()
    {
        $routes = [
            ['GET', '/api/tasks'],
            ['POST', '/api/tasks'],
            ['GET', '/api/tasks/1'],
            ['PUT', '/api/tasks/1'],
            ['DELETE', '/api/tasks/1'],
            ['GET', '/api/tasks/search'],
        ];

        foreach ($routes as [$method, $uri]) {
            $response = $this->json($method, $uri);
            $response->assertUnauthorized();
        }
    }

    /** @test */
    public function sanctum_authentication_works_correctly()
    {
        // Test successful authentication
        Sanctum::actingAs($this->user);
        $response = $this->getJson('/api/tasks');
        $response->assertOk();

        // Test token abilities
        Sanctum::actingAs($this->user, ['tasks:read']);
        $response = $this->getJson('/api/tasks');
        $response->assertOk();

        // Test invalid token abilities
        Sanctum::actingAs($this->user, ['wrong-ability']);
        $response = $this->getJson('/api/tasks');
        $response->assertForbidden();

        // Test expired token
        $expiredToken = $this->user->createToken('test-token', ['tasks:read'], now()->subDay());
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $expiredToken->plainTextToken,
        ])->getJson('/api/tasks');
        $response->assertUnauthorized();
    }

    /** @test */
    public function user_can_only_access_their_own_tasks()
    {
        $otherUser = User::factory()->create();
        $otherUserTask = Task::factory()->create(['user_id' => $otherUser->id]);
        $userTask = Task::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user);

        // Try to view other user's task
        $response = $this->getJson("/api/tasks/{$otherUserTask->id}");
        $response->assertForbidden();

        // Try to update other user's task
        $response = $this->putJson("/api/tasks/{$otherUserTask->id}", $this->taskData);
        $response->assertForbidden();

        // Try to delete other user's task
        $response = $this->deleteJson("/api/tasks/{$otherUserTask->id}");
        $response->assertForbidden();

        // Can access own task
        $response = $this->getJson("/api/tasks/{$userTask->id}");
        $response->assertOk();
    }

    /** @test */
    public function task_creation_validates_input_properly()
    {
        $this->actingAs($this->user);

        // Test empty data
        $response = $this->postJson('/api/tasks', []);
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'status', 'priority']);

        // Test invalid status
        $response = $this->postJson('/api/tasks', array_merge($this->taskData, [
            'status' => 'invalid_status'
        ]));
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        // Test invalid priority
        $response = $this->postJson('/api/tasks', array_merge($this->taskData, [
            'priority' => 'now'
        ]));
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['priority']);

        // Test invalid date format
        $response = $this->postJson('/api/tasks', array_merge($this->taskData, [
            'due_date' => 'invalid-date'
        ]));
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['due_date']);

        // Test past due date
        $response = $this->postJson('/api/tasks', array_merge($this->taskData, [
            'due_date' => now()->subDay()->format('Y-m-d H:i:s')
        ]));
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['due_date']);
    }

    /** @test */
    public function task_update_handles_edge_cases()
    {
        $task = Task::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Original Title',
            'status' => 'todo',
        ]);

        $this->actingAs($this->user);

        // Test partial update
        $response = $this->putJson("/api/tasks/{$task->id}", [
            'status' => 'completed'
        ]);
        $response->assertOk();
        $this->assertEquals('completed', $task->fresh()->status);
        $this->assertEquals('Original Title', $task->fresh()->title);

        // Test updating to same value
        $response = $this->putJson("/api/tasks/{$task->id}", [
            'status' => 'completed'
        ]);
        $response->assertOk();

        // Test invalid task ID
        $response = $this->putJson("/api/tasks/99999", [
            'status' => 'completed'
        ]);
        $response->assertNotFound();

        // Test concurrent updates
        $originalTask = $task->fresh();
        
        // Simulate another process updating the task
        $task->update(['title' => 'Updated by another process']);

        $response = $this->putJson("/api/tasks/{$task->id}", [
            'title' => 'Concurrent update',
            'version' => $originalTask->version
        ]);
        $response->assertStatus(409); // Conflict
    }

    /** @test */
    public function task_deletion_handles_edge_cases()
    {
        $task = Task::factory()->create([
            'user_id' => $this->user->id
        ]);

        $this->actingAs($this->user);

        // Test soft delete
        $response = $this->deleteJson("/api/tasks/{$task->id}");
        $response->assertOk();
        $this->assertSoftDeleted($task);

        // Test deleting already deleted task
        $response = $this->deleteJson("/api/tasks/{$task->id}");
        $response->assertNotFound();

        // Test force delete
        $response = $this->deleteJson("/api/tasks/{$task->id}?force=true");
        $response->assertOk();
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);

        // Test deleting non-existent task
        $response = $this->deleteJson("/api/tasks/99999");
        $response->assertNotFound();
    }

    /** @test */
    public function task_search_handles_edge_cases()
    {
        $this->actingAs($this->user);

        // Test empty search query
        $response = $this->getJson('/api/tasks/search');
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['query']);

        // Test minimum query length
        $response = $this->getJson('/api/tasks/search?query=a');
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['query']);

        // Test search with special characters
        $response = $this->getJson('/api/tasks/search?query=' . urlencode('!@#$%^&*()'));
        $response->assertOk()
            ->assertJsonCount(0, 'data');

        // Test search with very long query
        $longQuery = str_repeat('a', 1000);
        $response = $this->getJson('/api/tasks/search?query=' . urlencode($longQuery));
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['query']);
    }

    /** @test */
    public function task_filtering_handles_edge_cases()
    {
        $this->actingAs($this->user);

        // Test invalid status
        $response = $this->getJson('/api/tasks?is_completed=invalid');
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['is_completed']);

        // Test invalid priority
        $response = $this->getJson('/api/tasks?priority=now');
        $response->assertOk()
            ->assertJsonCount(0, 'data');

        // Test invalid date format
        $response = $this->getJson('/api/tasks?due_date=invalid-date');
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['due_date']);

        // Test future date
        $response = $this->getJson('/api/tasks?due_date=' . now()->addYears(100)->format('Y-m-d'));
        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** @test */
    public function task_pagination_handles_edge_cases()
    {
        Task::factory()->count(30)->create([
            'user_id' => $this->user->id
        ]);

        $this->actingAs($this->user);

        // Test invalid page number
        $response = $this->getJson('/api/tasks?page=0');
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['page']);

        // Test page number beyond last page
        $response = $this->getJson('/api/tasks?page=1000');
        $response->assertOk()
            ->assertJsonCount(0, 'data');

        // Test invalid per_page value
        $response = $this->getJson('/api/tasks?per_page=0');
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        // Test maximum per_page limit
        $response = $this->getJson('/api/tasks?per_page=1000');
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    /** @test */
    public function task_sorting_handles_edge_cases()
    {
        $this->actingAs($this->user);

        // Test invalid sort field
        $response = $this->getJson('/api/tasks?sort_by=invalid');
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['sort_by']);

        // Test invalid sort order
        $response = $this->getJson('/api/tasks?sort_by=created_at&sort_order=invalid');
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['sort_order']);

        // Test sorting empty result set
        $response = $this->getJson('/api/tasks?sort_by=created_at&sort_order=desc');
        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** @test */
    public function api_handles_rate_limiting()
    {
        $this->actingAs($this->user);

        // Make multiple requests quickly
        $hitRateLimit = false;
        for ($i = 0; $i < 100; $i++) {
            $response = $this->getJson('/api/tasks');
            if ($response->status() === 429) {
                $hitRateLimit = true;
                $this->assertTrue(true);
                break;
            }
        }

        $this->assertTrue($hitRateLimit, 'Rate limiting was not enforced');
    }

    /** @test */
    public function api_handles_malformed_requests()
    {
        $this->actingAs($this->user);

        // Test malformed JSON
        $response = $this->json('POST', '/api/tasks', 'invalid json');
        $response->assertStatus(400);

        // Test invalid content type
        $response = $this->post('/api/tasks', $this->taskData, [
            'CONTENT_TYPE' => 'text/plain',
            'Accept' => 'application/json'
        ]);
        $response->assertStatus(415);

        // Test missing accept header
        $response = $this->post('/api/tasks', $this->taskData, [
            'CONTENT_TYPE' => 'application/json'
        ]);
        $response->assertStatus(406);
    }
}