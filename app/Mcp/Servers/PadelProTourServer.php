<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetCompetitionTool;
use App\Mcp\Tools\GetStandingsTool;
use App\Mcp\Tools\ListMyCompetitionsTool;
use App\Mcp\Tools\ListMyMatchesTool;
use App\Mcp\Tools\ProposeMatchResultTool;
use App\Mcp\Tools\UpdateMatchBookingTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('PadelProTour')]
#[Version('1.0.0')]
#[Instructions('Gestiona las ligas y torneos de pádel del usuario autenticado en PadelProTour. Empieza con list_my_competitions o list_my_matches para obtener ids. Todo se hace en nombre del usuario del token y con sus mismos permisos que en la app. Antes de proponer un resultado o cambiar una reserva, confirma los datos con el usuario.')]
class PadelProTourServer extends Server
{
    protected array $tools = [
        ListMyCompetitionsTool::class,
        GetCompetitionTool::class,
        GetStandingsTool::class,
        ListMyMatchesTool::class,
        ProposeMatchResultTool::class,
        UpdateMatchBookingTool::class,
    ];
}
