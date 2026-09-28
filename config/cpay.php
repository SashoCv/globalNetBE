<?php

return [

    /*
    |--------------------------------------------------------------------------
    | cPay (CaSys) virtual POS — browser redirect integration
    |--------------------------------------------------------------------------
    |
    | The values below come from the "Redirect Code" template that the merchant
    | downloads from the cPay merchant module. PayToMerchant, MerchantName and
    | AmountCurrency must be sent exactly as they appear there.
    |
    */

    // Payment page the customer's browser is POSTed to. One URL per language.
    'payment_url' => env('CPAY_PAYMENT_URL', 'https://vpos.cpay.com.mk/mk-MK'),

    // Used only to verify that our checksum is built correctly — it never
    // charges a card. See "Test Payments" in the cPay testing procedures.
    'checksum_test_url' => env('CPAY_CHECKSUM_TEST_URL', 'https://vpos.cpay.com.mk/mk-MK/TestChecksum'),

    'merchant_id' => env('CPAY_MERCHANT_ID', ''),
    'merchant_name' => env('CPAY_MERCHANT_NAME', ''),

    // TEST_PASS during the testing period; replaced by the production key that
    // CaSys sends by e-mail once all test cases pass.
    'checksum_key' => env('CPAY_CHECKSUM_KEY', 'TEST_PASS'),

    /*
    | md5 or sha256 — it depends on how the terminal is defined at CaSys, not on
    | anything we choose. Confirm it on the TestChecksum page: it answers with
    | the digest it expected, and its length gives the algorithm away (32 hex
    | characters = MD5, 64 = SHA256).
    */
    'checksum_algo' => env('CPAY_CHECKSUM_ALGO', 'sha256'),

    // Payments from Macedonian merchants must be in denars.
    'currency' => env('CPAY_CURRENCY', 'MKD'),

    /*
    | The OK/FAIL URLs must live on the domain registered with the bank
    | (www.gnaeshop.mk), on the default 80/443 ports, and must be reachable by
    | cPay's servers — they receive both the browser redirect and the
    | server-to-server push notifications.
    */
    'ok_url' => env('CPAY_OK_URL', 'https://www.gnaeshop.mk/cpay/ok'),
    'fail_url' => env('CPAY_FAIL_URL', 'https://www.gnaeshop.mk/cpay/fail'),

    // Where the customer's browser is sent after we have processed the result.
    'return_url' => env('CPAY_RETURN_URL', 'https://www.gnaeshop.mk/payment/result'),

    /*
    | While the merchant still uses the TEST_PASS key, cPay declines anything
    | above 10 MKD. Keep this on until the production key is installed.
    */
    'test_mode' => env('CPAY_TEST_MODE', true),
    'test_max_amount' => env('CPAY_TEST_MAX_AMOUNT', 10),

    /*
    | Charge this amount instead of the real one while testing, so a real 5.000
    | MKD order can still be pushed through the 1-denar test cases. The real
    | amount is kept on the payment record. Leave empty outside testing.
    */
    'test_amount_override' => env('CPAY_TEST_AMOUNT', null),

];
