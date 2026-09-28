<?php

namespace App\Services\CPay;

use InvalidArgumentException;

/**
 * cPay CheckSum / ReturnCheckSum, per Appendix A of the merchant integration
 * specification.
 *
 * The header is  NN + "Name1,Name2,…,NameNN," + LLL1 LLL2 … LLLnn
 *   NN  = number of parameters, two digits, zero padded
 *   LLL = length of each value, three digits, zero padded, counted in
 *         characters (not bytes — "Тёст" is 4, not 8)
 *
 * The hashed input is  header + value1 + value2 + … + valueN + authentication key,
 * confirmed against cPay itself: its TestChecksum page returned exactly this
 * digest for one of our own requests.
 *
 * The hash function, however, depends on how the terminal is defined — the same
 * construction is documented with MD5 in specification v3.1 and with SHA256 in
 * v3.5, and the test merchant answers in MD5. So the algorithm is configuration
 * (CPAY_CHECKSUM_ALGO), and every new terminal is checked against
 * https://vpos.cpay.com.mk/mk-MK/TestChecksum before the first real payment: it
 * replies with the digest it expected, which says which of the two is in use.
 *
 * (The SHA256 example printed in v3.5 does not reproduce from its own input
 * string under any variant, so it is not a usable reference — this one is.)
 */
class CPayChecksum
{
    /**
     * Parameter order used when we build a request checksum. Only parameters
     * that are actually present (non-empty) take part.
     *
     * @var string[]
     */
    public const REQUEST_ORDER = [
        'AmountToPay',
        'PayToMerchant',
        'MerchantName',
        'AmountCurrency',
        'Details1',
        'Details2',
        'PaymentOKURL',
        'PaymentFailURL',
        'Fee',
        'CRef',
        'TransactionType',
        'Installment',
        'RPRef',
        'OriginalAmount',
        'OriginalCurrency',
        'FirstName',
        'LastName',
        'Address',
        'City',
        'Zip',
        'Country',
        'Telephone',
        'Email',
    ];

    public function __construct(
        private readonly string $key,
        private readonly string $algo = 'sha256',
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('cpay.checksum_key'),
            (string) config('cpay.checksum_algo'),
        );
    }

    /**
     * Build the CheckSumHeader for an ordered list of name => value pairs.
     *
     * @param  array<string, string>  $ordered
     */
    public function header(array $ordered): string
    {
        $names = array_keys($ordered);
        $lengths = '';

        foreach ($ordered as $value) {
            $lengths .= str_pad((string) mb_strlen((string) $value), 3, '0', STR_PAD_LEFT);
        }

        return str_pad((string) count($names), 2, '0', STR_PAD_LEFT)
            . implode(',', $names)
            . ','
            . $lengths;
    }

    /**
     * @param  array<string, string>  $ordered
     */
    public function inputString(array $ordered): string
    {
        return $this->header($ordered) . implode('', array_map(strval(...), array_values($ordered)));
    }

    /**
     * @param  array<string, string>  $ordered
     */
    public function checksum(array $ordered): string
    {
        return hash($this->algo, $this->inputString($ordered) . $this->key);
    }

    /**
     * Order the request parameters per REQUEST_ORDER, dropping empty ones —
     * "Parameters with empty values should not be added in the checksum header".
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    public function orderRequest(array $params): array
    {
        $ordered = [];

        foreach (self::REQUEST_ORDER as $name) {
            $value = $params[$name] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $ordered[$name] = (string) $value;
        }

        if ($ordered === []) {
            throw new InvalidArgumentException('No parameters to build a cPay checksum from.');
        }

        return $ordered;
    }

    /**
     * Recompute the checksum of a cPay response and compare it with the
     * ReturnCheckSum that came with it.
     *
     * The order and the lengths are taken from the ReturnCheckSumHeader that
     * cPay sends, not from our own ordering — cPay may add parameters, and the
     * first two parameters are swapped in the return header.
     *
     * @param  array<string, mixed>  $response  all POST/GET parameters as received
     */
    public function verifyReturn(array $response): bool
    {
        $header = (string) ($response['ReturnCheckSumHeader'] ?? '');
        $given = (string) ($response['ReturnCheckSum'] ?? '');

        if ($header === '' || $given === '') {
            return false;
        }

        $names = $this->parseHeaderNames($header);

        if ($names === null) {
            return false;
        }

        $ordered = [];

        foreach ($names as $name) {
            // A parameter named in the header but missing from the response
            // means the response is not the one the header describes.
            if (! array_key_exists($name, $response)) {
                return false;
            }

            $ordered[$name] = (string) $response[$name];
        }

        // Rebuilding the header from the values also validates every length
        // field, so a tampered value cannot pass by keeping the same total.
        if (! hash_equals($header, $this->header($ordered))) {
            return false;
        }

        // cPay returns the digest in upper case ("DC0CFF91…") while hash()
        // produces lower case, and the specification calls the field
        // "0-9, a-f, A-F" — so the comparison ignores case.
        return hash_equals(
            strtolower($this->checksum($ordered)),
            strtolower($given),
        );
    }

    /**
     * Pull the parameter names out of a checksum header.
     *
     * @return string[]|null
     */
    private function parseHeaderNames(string $header): ?array
    {
        if (! preg_match('/^(\d{2})(.*)$/s', $header, $m)) {
            return null;
        }

        $count = (int) $m[1];
        $rest = $m[2];

        $names = [];
        $offset = 0;

        for ($i = 0; $i < $count; $i++) {
            $comma = strpos($rest, ',', $offset);

            if ($comma === false) {
                return null;
            }

            $names[] = substr($rest, $offset, $comma - $offset);
            $offset = $comma + 1;
        }

        // What is left must be exactly three digits per parameter.
        if (strlen($rest) - $offset !== $count * 3) {
            return null;
        }

        return $names;
    }
}
