<?php

declare(strict_types=1);

use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\SchemaBuilder;

$site = require __DIR__ . '/../../App/config/schema.php';

return static function (SchemaBuilder $s) use ($site): void {
    $site($s);
    $s->form('f1_input', 'F1 input')->fields(Field::textarea('text', 'Text'), Field::textarea('explicit', 'Explicit')->max(20), Field::toggle('consent', 'Consent'));
};
