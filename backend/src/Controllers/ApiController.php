<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Modules\FelModule;
use App\Controllers\Modules\ManualModule;
use App\Controllers\Modules\SettingsModule;
use App\Controllers\Modules\SharedApi;
use App\Controllers\Modules\SyncModule;
use App\Controllers\Modules\TicketsModule;
use Config\Config;

/**
 * Controlador principal que compone lÃ³gica repartida en mÃ³dulos.
 */
class ApiController
{
    use SharedApi;
    use SettingsModule;
    use SyncModule;
    use TicketsModule;
    use FelModule;
    use ManualModule;

    private Config $config;
    /** @var array<string, array<string, bool>> */
    private array $tableColumnCache = [];

    public function __construct()
    {
        $this->config = new Config(__DIR__ . '/../../.env');
    }
}
