<?php

namespace craftyfm\craftformiefilemaker\models;

use Craft;
use craft\base\Model;

/**
 * formie-filemaker settings
 */
class Settings extends Model
{
    public $user = 'admin';
    public $pass = 'passw0rd123';
    public $authURL = 'https://fm.domain.com/fmi/data/v2/databases/mycooldb/sessions';

    public function defineRules(): array
    {
        return [
            [['user', 'pass', 'authURL'], 'required'],
            // ...
        ];
    }
}
