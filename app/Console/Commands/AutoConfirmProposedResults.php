<?php

namespace App\Console\Commands;

use App\Models\PadelMatch;
use App\Models\Ranking;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('matches:auto-confirm-results')]
#[Description('Confirma los resultados propuestos que llevan más de 48 h sin que un rival los valide')]
class AutoConfirmProposedResults extends Command
{
    public function handle(): int
    {
        $matches = PadelMatch::query()
            ->where('status', 'pending_validation')
            ->where('result_proposed_at', '<=', now()->subHours(PadelMatch::AUTO_CONFIRM_HOURS))
            ->with('phase.category')
            ->get();

        foreach ($matches as $match) {
            $match->forceFill(['status' => 'completed'])->save();
        }

        // Una recalculación por categoría, no por partido.
        $matches->map(fn (PadelMatch $match) => $match->phase->category)
            ->unique('id')
            ->each(fn ($category) => Ranking::recalculateForCategory($category));

        $this->info("Resultados confirmados automáticamente: {$matches->count()}");

        return self::SUCCESS;
    }
}
