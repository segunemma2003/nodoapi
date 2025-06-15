<?php

// database/migrations/2025_06_15_120000_create_support_tickets_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->unique(); // ST202500001
            $table->string('title');
            $table->text('description');
            $table->enum('status', ['open', 'in_progress', 'pending_customer', 'resolved', 'closed'])->default('open');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('category', [
                'technical_issue',
                'payment_issue',
                'account_issue',
                'feature_request',
                'billing_inquiry',
                'general_inquiry',
                'bug_report',
                'other'
            ])->default('general_inquiry');

            // Relationships
            $table->foreignId('business_id')->nullable()->constrained('businesses')->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users'); // Who created the ticket
            $table->foreignId('assigned_to')->nullable()->constrained('users'); // Which admin is handling it
            $table->foreignId('resolved_by')->nullable()->constrained('users'); // Who resolved it

            // Resolution details
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('last_response_at')->nullable();

            // Additional metadata
            $table->json('tags')->nullable(); // ['urgent', 'payment', 'technical']
            $table->json('attachments')->nullable(); // File paths
            $table->boolean('is_internal')->default(false); // Internal admin tickets vs business tickets
            $table->integer('response_count')->default(0);
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->decimal('actual_hours', 8, 2)->nullable();

            $table->timestamps();

            // Performance indexes
            $table->index(['status', 'priority', 'created_at']);
            $table->index(['business_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index(['category', 'status']);
            $table->index('ticket_number');
            $table->index(['created_at', 'status']);
            $table->index(['priority', 'status']);
        });

        // Ticket responses/comments table
        Schema::create('support_ticket_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users'); // Who responded
            $table->text('message');
            $table->enum('response_type', ['internal_note', 'customer_response', 'admin_response'])->default('admin_response');
            $table->json('attachments')->nullable();
            $table->boolean('is_solution')->default(false); // Mark as the solution
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
            $table->index(['user_id', 'response_type']);
        });

        // Ticket activity log
        Schema::create('support_ticket_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users'); // Who performed the action
            $table->string('action'); // 'created', 'assigned', 'status_changed', 'priority_changed', etc.
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('description'); // Human readable description
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_ticket_activities');
        Schema::dropIfExists('support_ticket_responses');
        Schema::dropIfExists('support_tickets');
    }
};
