<?php

namespace Tests\Unit;

use App\Features\PhoneAppSecrets;
use Tests\TestCase;

class PhoneAppSecretsTest extends TestCase
{
    public function test_a_key_token_or_password_with_a_value_is_found()
    {
        $settings = "APP_NAME=\"Bright Cleaning\"\nBACKEND_URL=https://bright.test\nBACKEND_TOKEN=abc123\nSTRIPE_KEY=pk_live_1\nexport MAIL_PASSWORD='hunter2'\n";

        $this->assertSame(['BACKEND_TOKEN', 'STRIPE_KEY', 'MAIL_PASSWORD'], PhoneAppSecrets::find($settings));
        $this->assertStringContainsString('Leave these empty: BACKEND_TOKEN, STRIPE_KEY, MAIL_PASSWORD.', PhoneAppSecrets::explain(['BACKEND_TOKEN', 'STRIPE_KEY', 'MAIL_PASSWORD']));
    }

    public function test_empty_and_null_secrets_and_harmless_names_pass()
    {
        // The starter's own settings: secrets left empty or null.
        $settings = "APP_KEY=\nDB_PASSWORD=\nREDIS_PASSWORD=null\nAWS_SECRET_ACCESS_KEY= # set on the server\nKEYBOARD_LAYOUT=qwerty\nMONKEY=1\nNATIVEPHP_APP_ID=com.example.bright\n";

        $this->assertSame([], PhoneAppSecrets::find($settings));
    }

    public function test_comments_and_odd_lines_are_not_read_as_settings()
    {
        $settings = "# API_TOKEN=abc\n\n   \nnot a setting\n=value\nAPI_TOKEN=abc\nAPI_TOKEN=def\n";

        $this->assertSame(['API_TOKEN'], PhoneAppSecrets::find($settings));
    }
}
