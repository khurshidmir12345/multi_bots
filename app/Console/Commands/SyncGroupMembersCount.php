<?php

namespace App\Console\Commands;

use App\Models\Bot;
use App\Models\TelegramGroup;
use App\Services\GroupService;
use Illuminate\Console\Command;

class SyncGroupMembersCount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'groups:sync-members-count';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Barcha guruhlarning members countini Telegram API\'dan yangilash';

    public function __construct(
        private GroupService $groupService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Guruhlarning members countini yangilash boshlandi...');

        // Barcha active guruhlarni olish
        $groups = TelegramGroup::with('bot')
            ->where('status', true)
            ->get();

        if ($groups->isEmpty()) {
            $this->warn('Active guruhlar topilmadi.');
            return 0;
        }

        $this->info("Jami {$groups->count()} ta guruh topildi.");

        $successCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($groups->count());
        $progressBar->start();

        foreach ($groups as $group) {
            $bot = $group->bot;

            if (!$bot || !$bot->is_active) {
                $progressBar->advance();
                $errorCount++;
                continue;
            }

            try {
                $success = $this->groupService->syncMembersCountFromTelegram($bot, $group);

                if ($success) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Xatolik (Group ID: {$group->id}): " . $e->getMessage());
                $errorCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Yakunlandi!");
        $this->table(
            ['Holat', 'Soni'],
            [
                ['Muvaffaqiyatli', $successCount],
                ['Xatolik', $errorCount],
                ['Jami', $groups->count()],
            ]
        );

        return 0;
    }
}
