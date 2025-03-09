<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Models\User;
use App\Enums\TaskPriority;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->task = Task::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Test Task',
            'description' => 'Test Description',
            'is_completed' => false,
            'priority' => TaskPriority::MEDIUM->value,
            'due_date' => now()->addDays(5),
        ]);
    }

    /** @test */
    public function task_belongs_to_a_user()
    {
        $this->assertInstanceOf(User::class, $this->task->author);
        $this->assertEquals($this->user->id, $this->task->author->id);
        $this->assertEquals($this->user->name, $this->task->author->name);
    }

    /** @test */
    public function task_attributes_are_properly_cast()
    {
        $this->assertIsString($this->task->title);
        $this->assertIsString($this->task->description);
        $this->assertIsString($this->task->status);
        $this->assertIsInt($this->task->priority);
        $this->assertInstanceOf(Carbon::class, $this->task->due_date);
        $this->assertInstanceOf(Carbon::class, $this->task->created_at);
        $this->assertInstanceOf(Carbon::class, $this->task->updated_at);
    }

    /** @test */
    public function task_has_correct_fillable_attributes()
    {
        $expectedFillable = [
            'user_id',
            'title',
            'description',
            'is_completed',
            'priority',
            'due_date',
        ];

        $this->assertEquals($expectedFillable, $this->task->getFillable());
    }

    /** @test */
    public function task_status_scope_filters_correctly()
    {
        // Create tasks with different statuses
        Task::factory()->create([
            'user_id' => $this->user->id,
            'is_completed' => true
        ]);

        Task::factory()->create([
            'user_id' => $this->user->id,
            'is_completed' => false
        ]);

        // Test each status
        $todoTasks = Task::status('todo')->get();
        $inProgressTasks = Task::status('in_progress')->get();
        $completedTasks = Task::status('completed')->get();

        $this->assertEquals(1, $todoTasks->count());
        $this->assertEquals(1, $inProgressTasks->count());
        $this->assertEquals(1, $completedTasks->count());

        $this->assertEquals('todo', $todoTasks->first()->status);
        $this->assertEquals('in_progress', $inProgressTasks->first()->status);
        $this->assertEquals('completed', $completedTasks->first()->status);
    }

    /** @test */
    public function task_priority_scope_filters_correctly()
    {
        // Create tasks with different priorities
        foreach ([0, 1, 2, 4, 5] as $priority) {
            Task::factory()->create([
                'user_id' => $this->user->id,
                'priority' => $priority
            ]);
        }

        // Test each priority level
        foreach ([0, 1, 2, 3, 4, 5] as $priority) {
            $tasks = Task::priority($priority)->get();
            $this->assertEquals(
                1, 
                $tasks->count(), 
                "Expected 1 task with priority {$priority}"
            );
            $this->assertEquals($priority, $tasks->first()->priority);
        }
    }

    /** @test */
    public function task_due_date_scope_filters_correctly()
    {
        $dates = [
            now()->subDay(),
            now(),
            now()->addDay(),
            now()->addDays(5),
            now()->addWeek(),
        ];

        // Create tasks with different due dates
        foreach ($dates as $date) {
            Task::factory()->create([
                'user_id' => $this->user->id,
                'due_date' => $date
            ]);
        }

        // Test filtering by specific date
        foreach ($dates as $date) {
            $formattedDate = $date->format('Y-m-d');
            $tasks = Task::dueDate($formattedDate)->get();
            
            $this->assertEquals(1, $tasks->count());
            $this->assertEquals(
                $formattedDate,
                $tasks->first()->due_date->format('Y-m-d')
            );
        }
    }

    /** @test */
    public function task_search_array_includes_required_fields()
    {
        $searchArray = $this->task->toSearchableArray();

        $requiredFields = [
            'id',
            'user_id',
            'title',
            'description',
            'status',
            'priority',
            'due_date',
            'created_at',
            'updated_at'
        ];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $searchArray);
        }

        // Verify the values are correct
        $this->assertEquals($this->task->id, $searchArray['id']);
        $this->assertEquals($this->task->user_id, $searchArray['user_id']);
        $this->assertEquals($this->task->title, $searchArray['title']);
        $this->assertEquals($this->task->description, $searchArray['description']);
        $this->assertEquals($this->task->status, $searchArray['status']);
        $this->assertEquals($this->task->priority, $searchArray['priority']);
    }

    /** @test */
    public function task_scope_for_user_filters_correctly()
    {
        // Create tasks for different users
        $otherUser = User::factory()->create();
        Task::factory()->count(3)->create(['user_id' => $otherUser->id]);
        Task::factory()->count(2)->create(['user_id' => $this->user->id]);

        $userTasks = Task::forUser($this->user->id)->get();
        $otherUserTasks = Task::forUser($otherUser->id)->get();

        // Including the task created in setUp()
        $this->assertEquals(3, $userTasks->count());
        $this->assertEquals(3, $otherUserTasks->count());

        // Verify all tasks belong to the correct user
        $userTasks->each(function ($task) {
            $this->assertEquals($this->user->id, $task->user_id);
        });

        $otherUserTasks->each(function ($task) use ($otherUser) {
            $this->assertEquals($otherUser->id, $task->user_id);
        });
    }

    /** @test */
    public function task_scopes_can_be_chained()
    {
        // Create tasks with various combinations
        Task::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'todo',
            'priority' => 5,
            'due_date' => now()->addDay(),
        ]);

        Task::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'completed',
            'priority' => 5,
            'due_date' => now()->addDay(),
        ]);

        // Test chaining multiple scopes
        $tasks = Task::forUser($this->user->id)
            ->status('todo')
            ->priority(5)
            ->dueDate(now()->addDay()->format('Y-m-d'))
            ->get();

        $this->assertEquals(1, $tasks->count());
        $task = $tasks->first();
        
        $this->assertEquals($this->user->id, $task->user_id);
        $this->assertEquals('todo', $task->status);
        $this->assertEquals(5, $task->priority);
        $this->assertEquals(
            now()->addDay()->format('Y-m-d'),
            $task->due_date->format('Y-m-d')
        );
    }

    /** @test */
    public function task_handles_null_values_correctly()
    {
        $task = Task::factory()->create([
            'user_id' => $this->user->id,
            'description' => null,
            'due_date' => null,
        ]);

        $this->assertNull($task->description);
        $this->assertNull($task->due_date);

        // Test search array with null values
        $searchArray = $task->toSearchableArray();
        $this->assertNull($searchArray['description']);
        $this->assertNull($searchArray['due_date']);
    }

    /** @test */
    public function task_timestamps_are_updated_correctly()
    {
        $originalCreatedAt = $this->task->created_at;
        $originalUpdatedAt = $this->task->updated_at;

        sleep(1); // Ensure time difference
        $this->task->update(['title' => 'Updated Title']);

        $this->assertEquals(
            $originalCreatedAt->timestamp,
            $this->task->created_at->timestamp
        );
        $this->assertGreaterThan(
            $originalUpdatedAt->timestamp,
            $this->task->updated_at->timestamp
        );
    }

    /** @test */
    public function task_soft_deletes_work_correctly()
    {
        $taskId = $this->task->id;
        $this->task->delete();

        // Task should not be found in normal queries
        $this->assertNull(Task::find($taskId));

        // Task should be found when including trashed
        $this->assertNotNull(Task::withTrashed()->find($taskId));

        // Task should be restorable
        $this->task->restore();
        $this->assertNotNull(Task::find($taskId));
    }
}