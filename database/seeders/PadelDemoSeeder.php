<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\Competition;
use App\Models\MatchdayPairing;
use App\Models\PadelMatch;
use App\Models\Pair;
use App\Models\Phase;
use App\Models\Ranking;
use App\Models\Registration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de ejemplo para desarrollar el frontend contra una API con
 * contenido realista: un torneo (con fase de grupo + eliminatoria), una
 * liga de pareja fija (con calendario round-robin y ranking calculado)
 * y una liga individual con rotación (con un emparejamiento de jornada).
 *
 * Cuenta de acceso: demo@padelprotour.test / password
 */
class PadelDemoSeeder extends Seeder
{
    public function run(): void
    {
        $organizer = User::factory()->create([
            'name' => 'Demo Organizador',
            'email' => 'demo@padelprotour.test',
            'password' => Hash::make('password'),
            'level' => '3ª',
            'club' => 'Club Pádel Sur',
        ]);

        $names = [
            'Laura Gómez', 'Carlos Ruiz', 'Marta Fernández', 'Javier Sánchez',
            'Elena Torres', 'Pablo Díaz', 'Sara Romero', 'Diego Navarro',
            'Lucía Ortega', 'Álvaro Molina', 'Nuria Castro', 'Iván Vidal',
        ];
        $players = collect($names)->map(fn (string $name) => User::factory()->create(['name' => $name]));

        $this->seedTournament($organizer, $players);
        $this->seedFixedPairLeague($organizer, $players);
        $this->seedRotatingLeague($organizer, $players);
    }

    private function seedTournament(User $organizer, Collection $players): void
    {
        $tournament = Competition::factory()->create([
            'organizer_id' => $organizer->id,
            'type' => 'tournament',
            'name' => 'Torneo Apertura PadelProTour',
            'venue' => 'Club Pádel Sur',
            'start_date' => now()->addWeek(),
            'end_date' => now()->addWeek()->addDays(2),
            'registration_closes_at' => now()->addDays(3),
        ]);

        $category = Category::factory()->create([
            'competition_id' => $tournament->id,
            'name' => '3ª Mixta',
            'match_format' => '3 sets, super tie-break',
            'slots' => 8,
            'registration_mode' => null,
        ]);

        // El organizador también compite en su propio torneo, junto a Laura.
        $pairs = collect([
            Pair::factory()->create(['player1_id' => $organizer->id, 'player2_id' => $players[0]->id]),
            Pair::factory()->create(['player1_id' => $players[1]->id, 'player2_id' => $players[2]->id]),
            Pair::factory()->create(['player1_id' => $players[3]->id, 'player2_id' => $players[4]->id]),
            Pair::factory()->create(['player1_id' => $players[5]->id, 'player2_id' => $players[6]->id]),
        ]);

        $pairs->each(fn (Pair $pair) => Registration::factory()->create([
            'category_id' => $category->id,
            'pair_id' => $pair->id,
            'player_id' => null,
            'status' => 'confirmed',
        ]));

        $group = Phase::factory()->create([
            'category_id' => $category->id,
            'type' => 'group',
            'name' => 'Grupo A',
            'order' => 1,
        ]);

        $this->completedMatch($group, $pairs[0], $pairs[1], [[6, 2], [6, 3]]);
        $this->completedMatch($group, $pairs[2], $pairs[3], [[7, 5], [4, 6], [6, 4]]);
        $this->scheduledMatch($group, $pairs[0], $pairs[2], now()->addWeek()->addHours(2), 'Pista 1');

        $semifinal = Phase::factory()->create([
            'category_id' => $category->id,
            'type' => 'elimination_round',
            'name' => 'Semifinales',
            'order' => 2,
        ]);
        $this->scheduledMatch($semifinal, $pairs[0], $pairs[3], now()->addWeek()->addDay(), 'Pista Central');

        ChatMessage::factory()->create([
            'competition_id' => $tournament->id,
            'author_id' => $organizer->id,
            'body' => '¡Bienvenidos al torneo! El cuadro de grupos ya está publicado.',
        ]);
        ChatMessage::factory()->create([
            'competition_id' => $tournament->id,
            'author_id' => $players[1]->id,
            'body' => '¿A qué hora es el partido de mañana?',
        ]);
        ChatMessage::factory()->create([
            'competition_id' => $tournament->id,
            'author_id' => $organizer->id,
            'body' => 'A las 10:00 en pista 1, ¡puntuales!',
        ]);
    }

    private function seedFixedPairLeague(User $organizer, Collection $players): void
    {
        $league = Competition::factory()->create([
            'organizer_id' => $organizer->id,
            'type' => 'league',
            'name' => 'Liga de Otoño',
            'venue' => 'Club Pádel Sur',
            'start_date' => now()->subWeeks(2),
            'end_date' => now()->addWeeks(6),
        ]);

        $category = Category::factory()->create([
            'competition_id' => $league->id,
            'name' => '4ª Masculina',
            'registration_mode' => null,
            'slots' => 10,
        ]);

        // 5 parejas -> número impar, para que el calendario incluya un bye.
        $pairSeeds = [[0, 1], [2, 3], [4, 5], [6, 7], [8, 9]];
        $pairs = collect($pairSeeds)->map(fn (array $seed) => Pair::factory()->create([
            'player1_id' => $players[$seed[0]]->id,
            'player2_id' => $players[$seed[1]]->id,
        ]));

        $pairs->each(fn (Pair $pair) => Registration::factory()->create([
            'category_id' => $category->id,
            'pair_id' => $pair->id,
            'player_id' => null,
            'status' => 'confirmed',
        ]));

        $phases = Phase::generateRoundRobinForCategory($category, $pairs->pluck('id')->all());

        // Las 2 primeras jornadas ya se jugaron; el resto queda por delante.
        $phases->take(2)->each(function (Phase $phase) {
            $phase->matches->each(function (PadelMatch $match) {
                $match->update(['status' => 'completed', 'winner_side' => 1]);
                $match->matchSets()->create(['set_number' => 1, 'side1_games' => 6, 'side2_games' => 4]);
                $match->matchSets()->create(['set_number' => 2, 'side1_games' => 6, 'side2_games' => 3]);
            });
        });

        Ranking::recalculateForCategory($category);
    }

    private function seedRotatingLeague(User $organizer, Collection $players): void
    {
        $clubAdmin = $players[7];

        $league = Competition::factory()->create([
            'organizer_id' => $clubAdmin->id,
            'type' => 'league',
            'name' => 'Liga Individual Miércoles',
            'venue' => 'Club Pádel Sur',
            'start_date' => now()->subWeek(),
        ]);

        $category = Category::factory()->create([
            'competition_id' => $league->id,
            'name' => '2ª Individual',
            'registration_mode' => 'individual_rotating',
        ]);

        // El organizador de la demo participa aquí como jugador, no como
        // organizador -- así /me/competitions muestra ambos roles a la vez.
        $participants = collect([$organizer, $players[8], $players[9], $players[10]]);
        $participants->each(fn (User $user) => Registration::factory()->create([
            'category_id' => $category->id,
            'pair_id' => null,
            'player_id' => $user->id,
            'status' => 'confirmed',
        ]));

        $matchday = Phase::factory()->create([
            'category_id' => $category->id,
            'type' => 'matchday',
            'name' => 'Jornada 1',
            'order' => 1,
        ]);

        MatchdayPairing::factory()->create([
            'phase_id' => $matchday->id,
            'player1_id' => $participants[0]->id,
            'player2_id' => $participants[1]->id,
        ]);
        MatchdayPairing::factory()->create([
            'phase_id' => $matchday->id,
            'player1_id' => $participants[2]->id,
            'player2_id' => $participants[3]->id,
        ]);

        $match = PadelMatch::factory()->create([
            'phase_id' => $matchday->id,
            'side1_player1_id' => $participants[0]->id,
            'side1_player2_id' => $participants[1]->id,
            'side2_player1_id' => $participants[2]->id,
            'side2_player2_id' => $participants[3]->id,
            'status' => 'completed',
            'winner_side' => 2,
        ]);
        $match->matchSets()->create(['set_number' => 1, 'side1_games' => 4, 'side2_games' => 6]);
        $match->matchSets()->create(['set_number' => 2, 'side1_games' => 3, 'side2_games' => 6]);

        Ranking::recalculateForCategory($category);
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $sets
     */
    private function completedMatch(Phase $phase, Pair $side1, Pair $side2, array $sets): PadelMatch
    {
        $side1Sets = collect($sets)->filter(fn ($set) => $set[0] > $set[1])->count();
        $side2Sets = count($sets) - $side1Sets;

        $match = PadelMatch::factory()->create([
            'phase_id' => $phase->id,
            'side1_player1_id' => $side1->player1_id,
            'side1_player2_id' => $side1->player2_id,
            'side2_player1_id' => $side2->player1_id,
            'side2_player2_id' => $side2->player2_id,
            'status' => 'completed',
            'winner_side' => $side1Sets > $side2Sets ? 1 : 2,
        ]);

        foreach ($sets as $number => [$side1Games, $side2Games]) {
            $match->matchSets()->create([
                'set_number' => $number + 1,
                'side1_games' => $side1Games,
                'side2_games' => $side2Games,
            ]);
        }

        return $match;
    }

    private function scheduledMatch(Phase $phase, Pair $side1, Pair $side2, Carbon $scheduledAt, string $court): PadelMatch
    {
        return PadelMatch::factory()->create([
            'phase_id' => $phase->id,
            'side1_player1_id' => $side1->player1_id,
            'side1_player2_id' => $side1->player2_id,
            'side2_player1_id' => $side2->player1_id,
            'side2_player2_id' => $side2->player2_id,
            'status' => 'scheduled',
            'winner_side' => null,
            'scheduled_at' => $scheduledAt,
            'court' => $court,
        ]);
    }
}
