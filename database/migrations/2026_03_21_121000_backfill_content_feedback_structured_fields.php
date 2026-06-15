<?php

use App\Models\ContentFeedbackEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('content_feedback_entries')) {
            return;
        }

        DB::table('content_feedback_entries')
            ->select(['id', 'category', 'normalized_category', 'scope', 'severity', 'reason', 'action', 'sentiment'])
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $category = trim((string) ($row->category ?? ''));
                    $scope = trim((string) ($row->scope ?? ''));
                    $reason = trim((string) ($row->reason ?? ''));
                    $action = trim((string) ($row->action ?? ''));
                    $sentiment = trim((string) ($row->sentiment ?? ContentFeedbackEntry::SENTIMENT_DISLIKE));

                    $normalizedCategory = trim((string) ($row->normalized_category ?? ''));
                    if ($normalizedCategory === '') {
                        $normalizedCategory = ContentFeedbackEntry::normalizeCategory($category, $reason, $scope);
                    }

                    $severity = trim((string) ($row->severity ?? ''));
                    if ($severity === '') {
                        $severity = ContentFeedbackEntry::resolveSeverity(null, $normalizedCategory, $reason, $action, $sentiment);
                    }

                    DB::table('content_feedback_entries')
                        ->where('id', $row->id)
                        ->update([
                            'normalized_category' => $normalizedCategory !== '' ? $normalizedCategory : null,
                            'severity' => $severity !== '' ? $severity : null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // No-op: this migration backfills derived fields without altering source feedback text.
    }
};
