<?php

namespace Everest\Tests\Unit\Models\Billing;

use Everest\Tests\TestCase;
use Everest\Models\Billing\BillingException;

class BillingExceptionModelTest extends TestCase
{
    /**
     * Test that all exception type constants are defined.
     */
    public function testExceptionTypeConstantsAreDefined()
    {
        $this->assertEquals('payment', BillingException::TYPE_PAYMENT);
        $this->assertEquals('deployment', BillingException::TYPE_DEPLOYMENT);
        $this->assertEquals('storefront', BillingException::TYPE_STOREFRONT);
        $this->assertEquals('webhook', BillingException::TYPE_WEBHOOK);
        $this->assertEquals('refund', BillingException::TYPE_REFUND);
        $this->assertEquals('validation', BillingException::TYPE_VALIDATION);
    }

    /**
     * Test that all new exception types are included in validation rules.
     *
     * @dataProvider exceptionTypeProvider
     */
    public function testNewExceptionTypesAreValidated(string $type)
    {
        $rules = BillingException::$validationRules;
        
        $this->assertArrayHasKey('exception_type', $rules);
        $this->assertStringContainsString($type, $rules['exception_type']);
    }

    /**
     * Exception type data provider.
     */
    public static function exceptionTypeProvider(): array
    {
        return [
            'payment' => ['payment'],
            'deployment' => ['deployment'],
            'storefront' => ['storefront'],
            'webhook' => ['webhook'],
            'refund' => ['refund'],
            'validation' => ['validation'],
        ];
    }

    /**
     * Test that order_id is nullable in validation rules.
     */
    public function testOrderIdIsNullableInValidationRules()
    {
        $rules = BillingException::$validationRules;
        
        $this->assertArrayHasKey('order_id', $rules);
        $this->assertStringContainsString('nullable', $rules['order_id']);
    }

    /**
     * Test that UUID is required in validation rules.
     */
    public function testUuidIsRequiredInValidationRules()
    {
        $rules = BillingException::$validationRules;
        
        $this->assertArrayHasKey('uuid', $rules);
        $this->assertStringContainsString('required', $rules['uuid']);
    }

    /**
     * Test that title is required in validation rules.
     */
    public function testTitleIsRequiredInValidationRules()
    {
        $rules = BillingException::$validationRules;
        
        $this->assertArrayHasKey('title', $rules);
        $this->assertStringContainsString('required', $rules['title']);
    }

    /**
     * Test that description is required in validation rules.
     */
    public function testDescriptionIsRequiredInValidationRules()
    {
        $rules = BillingException::$validationRules;
        
        $this->assertArrayHasKey('description', $rules);
        $this->assertStringContainsString('required', $rules['description']);
    }

    /**
     * Test that model has correct fillable attributes.
     */
    public function testModelHasCorrectFillableAttributes()
    {
        $model = new BillingException();
        $fillable = $model->getFillable();
        
        $this->assertContains('uuid', $fillable);
        $this->assertContains('order_id', $fillable);
        $this->assertContains('title', $fillable);
        $this->assertContains('description', $fillable);
        $this->assertContains('exception_type', $fillable);
    }

    /**
     * Test that order_id is cast to integer.
     */
    public function testOrderIdIsCastToInteger()
    {
        $model = new BillingException();
        $casts = $model->getCasts();
        
        $this->assertArrayHasKey('order_id', $casts);
        $this->assertEquals('int', $casts['order_id']);
    }
}
