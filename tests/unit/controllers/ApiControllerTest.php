<?php

namespace cogapp\searchindex\tests\unit\controllers;

use cogapp\searchindex\controllers\ApiController;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ApiController helper methods.
 *
 * Full integration testing of actions requires a running Craft application,
 * so these tests focus on the private helper methods via reflection.
 */
class ApiControllerTest extends TestCase
{
    /**
     * Call a private method on the ApiController via reflection.
     */
    private function callPrivateMethod(string $method, array $args = []): mixed
    {
        $controller = $this->createPartialMock(ApiController::class, []);
        $ref = new \ReflectionMethod($controller, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($controller, $args);
    }

    // -- _decodeJson ----------------------------------------------------------

    public function testDecodeJsonValidObject(): void
    {
        $result = $this->callPrivateMethod('_decodeJson', ['{"title":"asc"}', 'sort']);

        $this->assertSame(['title' => 'asc'], $result);
    }

    public function testDecodeJsonValidArray(): void
    {
        $result = $this->callPrivateMethod('_decodeJson', ['[{"index":"places","query":"castle"}]', 'searches']);

        $this->assertIsArray($result);
        $this->assertSame('places', $result[0]['index']);
    }

    public function testDecodeJsonInvalidReturnsFalse(): void
    {
        $result = $this->callPrivateMethod('_decodeJson', ['not valid json', 'sort']);

        $this->assertFalse($result);
    }

    public function testDecodeJsonScalarReturnsFalse(): void
    {
        $result = $this->callPrivateMethod('_decodeJson', ['"just a string"', 'sort']);

        $this->assertFalse($result);
    }

    public function testDecodeJsonNumericReturnsFalse(): void
    {
        $result = $this->callPrivateMethod('_decodeJson', ['42', 'sort']);

        $this->assertFalse($result);
    }

    // -- _isTruthy ------------------------------------------------------------

    public function testIsTruthyNull(): void
    {
        $result = $this->callPrivateMethod('_isTruthy', [null]);

        $this->assertFalse($result);
    }

    public function testIsTruthyOne(): void
    {
        $result = $this->callPrivateMethod('_isTruthy', ['1']);

        $this->assertTrue($result);
    }

    public function testIsTruthyTrue(): void
    {
        $result = $this->callPrivateMethod('_isTruthy', ['true']);

        $this->assertTrue($result);
    }

    public function testIsTruthyTrueUppercase(): void
    {
        $result = $this->callPrivateMethod('_isTruthy', ['TRUE']);

        $this->assertTrue($result);
    }

    public function testIsTruthyYes(): void
    {
        $result = $this->callPrivateMethod('_isTruthy', ['yes']);

        $this->assertTrue($result);
    }

    public function testIsTruthyZero(): void
    {
        $result = $this->callPrivateMethod('_isTruthy', ['0']);

        $this->assertFalse($result);
    }

    public function testIsTruthyFalse(): void
    {
        $result = $this->callPrivateMethod('_isTruthy', ['false']);

        $this->assertFalse($result);
    }

    public function testIsTruthyEmptyString(): void
    {
        $result = $this->callPrivateMethod('_isTruthy', ['']);

        $this->assertFalse($result);
    }

    public function testIsTruthyRandomString(): void
    {
        $result = $this->callPrivateMethod('_isTruthy', ['maybe']);

        $this->assertFalse($result);
    }

    // -- _decodeJson edge cases -----------------------------------------------

    public function testDecodeJsonEmptyObject(): void
    {
        $result = $this->callPrivateMethod('_decodeJson', ['{}', 'filters']);

        $this->assertSame([], $result);
    }

    public function testDecodeJsonEmptyArray(): void
    {
        $result = $this->callPrivateMethod('_decodeJson', ['[]', 'searches']);

        $this->assertSame([], $result);
    }

    public function testDecodeJsonNestedObject(): void
    {
        $result = $this->callPrivateMethod('_decodeJson', ['{"region":["Highland","Central"]}', 'filters']);

        $this->assertSame(['region' => ['Highland', 'Central']], $result);
    }

    public function testDecodeJsonBooleanValueReturnsFalse(): void
    {
        $result = $this->callPrivateMethod('_decodeJson', ['true', 'param']);

        $this->assertFalse($result);
    }

    public function testDecodeJsonNullValueReturnsFalse(): void
    {
        $result = $this->callPrivateMethod('_decodeJson', ['null', 'param']);

        $this->assertFalse($result);
    }
}
