<?php

namespace Tests\Unit;

use App\Services\CPay\CPayChecksum;
use PHPUnit\Framework\TestCase;

class CPayChecksumTest extends TestCase
{
    private function checksum(): CPayChecksum
    {
        return new CPayChecksum('TEST_PASS');
    }

    public function test_header_counts_parameters_and_value_lengths(): void
    {
        $header = $this->checksum()->header([
            'AmountToPay' => '100',
            'PayToMerchant' => '1234567890',
            'Details1' => 'Naracka 1',
        ]);

        $this->assertSame('03AmountToPay,PayToMerchant,Details1,003010009', $header);
    }

    /**
     * "Length of each character should be count 1" — the testing procedures
     * require a transaction with international characters in Details1.
     */
    public function test_lengths_count_characters_not_bytes(): void
    {
        $header = $this->checksum()->header(['Details1' => 'Тёст Strîñğ']);

        $this->assertSame('01Details1,011', $header);
    }

    public function test_empty_parameters_are_left_out(): void
    {
        $ordered = $this->checksum()->orderRequest([
            'AmountToPay' => '100',
            'PayToMerchant' => '1234567890',
            'MerchantName' => 'GNAESHOP',
            'AmountCurrency' => 'MKD',
            'Details1' => 'Naracka 1',
            'Details2' => '7',
            'PaymentOKURL' => 'https://www.gnaeshop.mk/cpay/ok',
            'PaymentFailURL' => 'https://www.gnaeshop.mk/cpay/fail',
            'City' => '',
            'Telephone' => null,
        ]);

        $this->assertArrayNotHasKey('City', $ordered);
        $this->assertArrayNotHasKey('Telephone', $ordered);
        $this->assertSame('AmountToPay', array_key_first($ordered));
    }

    /**
     * Locked-in reference: cPay's own TestChecksum page rejected this request
     * and reported the digest it expected, which is reproduced here exactly.
     * It is the only trustworthy proof that our input string — header, comma
     * separators, three-digit lengths, values in header order, key appended —
     * matches what cPay builds on its side.
     */
    public function test_the_input_string_matches_the_digest_cpay_expected(): void
    {
        $ordered = [
            'AmountToPay' => '100',
            'PayToMerchant' => '1000000047',
            'MerchantName' => 'GNAESHOP',
            'AmountCurrency' => 'MKD',
            'Details1' => 'Naracka GNA-2026-00013',
            'Details2' => '1',
            'PaymentOKURL' => 'http://127.0.0.1:9095/api/cpay/ok',
            'PaymentFailURL' => 'http://127.0.0.1:9095/api/cpay/fail',
            'City' => 'Skopje',
            'Country' => '807',
            'Telephone' => '38970123456',
            'Email' => 'ordinacija.test@example.com',
        ];

        $this->assertSame(
            '3bf27a01d4ad39b8c1cd3170e48ddfa4',
            (new CPayChecksum('TEST_PASS', 'md5'))->checksum($ordered),
        );
    }

    public function test_the_algorithm_is_configurable(): void
    {
        $ordered = ['Details1' => 'Naracka 1'];

        $this->assertSame(32, strlen((new CPayChecksum('TEST_PASS', 'md5'))->checksum($ordered)));
        $this->assertSame(64, strlen((new CPayChecksum('TEST_PASS', 'sha256'))->checksum($ordered)));
    }

    public function test_a_genuine_response_verifies(): void
    {
        $this->assertTrue($this->checksum()->verifyReturn($this->response()));
    }

    /**
     * cPay sends the ReturnCheckSum in upper case; hash() returns lower case.
     * Comparing them literally rejects every genuine payment confirmation.
     */
    public function test_an_upper_case_return_checksum_verifies(): void
    {
        $response = $this->response();
        $response['ReturnCheckSum'] = strtoupper($response['ReturnCheckSum']);

        $this->assertTrue($this->checksum()->verifyReturn($response));
    }

    public function test_a_tampered_amount_is_rejected(): void
    {
        $response = $this->response();
        $response['AmountToPay'] = '10000';

        $this->assertFalse($this->checksum()->verifyReturn($response));
    }

    public function test_a_response_signed_with_another_key_is_rejected(): void
    {
        $response = $this->response();

        $this->assertFalse((new CPayChecksum('OTHER_KEY'))->verifyReturn($response));
    }

    public function test_a_response_without_a_checksum_is_rejected(): void
    {
        $response = $this->response();
        unset($response['ReturnCheckSum']);

        $this->assertFalse($this->checksum()->verifyReturn($response));
    }

    public function test_a_parameter_named_in_the_header_but_missing_is_rejected(): void
    {
        $response = $this->response();
        unset($response['Details2']);

        $this->assertFalse($this->checksum()->verifyReturn($response));
    }

    /**
     * cPay may add parameters of its own; the return header is what decides
     * which ones take part, so an unknown extra must not break verification.
     */
    public function test_extra_parameters_outside_the_header_are_ignored(): void
    {
        $response = $this->response();
        $response['SomethingNew'] = 'x';

        $this->assertTrue($this->checksum()->verifyReturn($response));
    }

    /**
     * A cPay response echoes the request with the first two parameters swapped
     * and cPayPaymentRef appended.
     *
     * @return array<string, string>
     */
    private function response(): array
    {
        $ordered = [
            'PayToMerchant' => '1234567890',
            'AmountToPay' => '100',
            'MerchantName' => 'GNAESHOP',
            'AmountCurrency' => 'MKD',
            'Details1' => 'Naracka GNA-000123',
            'Details2' => '42',
            'PaymentOKURL' => 'https://www.gnaeshop.mk/cpay/ok',
            'PaymentFailURL' => 'https://www.gnaeshop.mk/cpay/fail',
            'cPayPaymentRef' => '25710201',
        ];

        $checksum = $this->checksum();

        return $ordered + [
            'ReturnCheckSumHeader' => $checksum->header($ordered),
            'ReturnCheckSum' => $checksum->checksum($ordered),
        ];
    }
}
