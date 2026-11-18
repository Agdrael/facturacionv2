<?php

namespace Database\Seeders;

use App\Services\Access\AccessControlService;
use Illuminate\Database\Seeder;

class AccessControlSeeder extends Seeder
{
    public function run(AccessControlService $access): void
    {
        $access->synchronize();
    }
}
