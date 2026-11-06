<?php

use App\Modules\Personal\Controllers\PersonalIngresoUpdateController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\URL;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
URL::forceRootUrl('http://synthetic.local');

echo $app->make(PersonalIngresoUpdateController::class)->publicShow()->getContent();
