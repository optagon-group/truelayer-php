<?php

declare(strict_types=1);

use TrueLayer\Constants\AuthorizationFlowActionTypes;
use TrueLayer\Constants\AuthorizationFlowStatusTypes;
use TrueLayer\Interfaces\Payment\AuthorizationFlow\Action\ProviderSelectionActionInterface;
use TrueLayer\Interfaces\Payment\AuthorizationFlow\Action\RedirectActionInterface;
use TrueLayer\Interfaces\Payment\AuthorizationFlow\ConfigurationInterface;
use TrueLayer\Interfaces\Payment\PaymentAuthorizingInterface;
use TrueLayer\Interfaces\Payment\PaymentCreatedInterface;
use TrueLayer\Interfaces\Payment\PaymentExecutedInterface;
use TrueLayer\Interfaces\Payment\PaymentFailedInterface;
use TrueLayer\Interfaces\Provider\ProviderInterface;

\it('creates an IBAN payment', function () {
    $helper = \paymentHelper();
    $created = $helper->create(
        $helper->bankTransferMethod($helper->ibanBeneficiary()), $helper->user(), 'EUR'
    );

    \expect($created)->toBeInstanceOf(PaymentCreatedInterface::class);
    \expect($created->getId())->toBeString();
    \expect($created->getResourceToken())->toBeString();
    \expect($created->getUserId())->toBeString();
    \expect($created->getDetails()->getMetadata())->toMatchArray([
        'metadata_key_1' => 'metadata_value_1',
        'metadata_key_2' => 'metadata_value_2',
        'metadata_key_3' => 'metadata_value_3',
    ]);
});

\it('creates a SCAN payment', function () {
    $created = \paymentHelper()->create();

    \expect($created)->toBeInstanceOf(PaymentCreatedInterface::class);
    \expect($created->getId())->toBeString();
    \expect($created->getResourceToken())->toBeString();
    \expect($created->getUserId())->toBeString();
    \expect($created->getDetails()->getMetadata())->toMatchArray([
        'metadata_key_1' => 'metadata_value_1',
        'metadata_key_2' => 'metadata_value_2',
        'metadata_key_3' => 'metadata_value_3',
    ]);

    return $created;
});

\it('starts payment authorization', function (PaymentCreatedInterface $created) {
    $response = \client()->startPaymentAuthorization($created, 'https://console.truelayer.com/redirect-page');

    /** @var ProviderSelectionActionInterface $next */
    $next = $response->getNextAction();

    \expect($response->getStatus())->toBe(AuthorizationFlowStatusTypes::AUTHORIZING);
    \expect($next)->toBeInstanceOf(ProviderSelectionActionInterface::class);
    \expect($next->getType())->toBe(AuthorizationFlowActionTypes::PROVIDER_SELECTION);
    \expect($next->getProviders()[0])->toBeInstanceOf(ProviderInterface::class);

    return $created;
})->depends('it creates a SCAN payment');

\it('retrieves payment as authorizing - provider selection', function (PaymentCreatedInterface $created) {
    /** @var PaymentAuthorizingInterface $payment */
    $payment = $created->getDetails();

    \expect($payment)->toBeInstanceOf(PaymentAuthorizingInterface::class);
    \expect($payment->getAuthorizationFlowNextAction())->toBeInstanceOf(ProviderSelectionActionInterface::class);
    \expect($payment->getAuthorizationFlowConfig())->toBeInstanceOf(ConfigurationInterface::class);
    \expect($payment->getMetadata())->toMatchArray([
        'metadata_key_1' => 'metadata_value_1',
        'metadata_key_2' => 'metadata_value_2',
        'metadata_key_3' => 'metadata_value_3',
    ]);

    return $created;
})->depends('it starts payment authorization');

\it('submits provider', function (PaymentCreatedInterface $created) {
    $response = \client()->submitPaymentProvider($created, 'mock-payments-gb-redirect');

    /** @var RedirectActionInterface $next */
    $next = $response->getNextAction();

    \expect($response->getStatus())->toBe(AuthorizationFlowStatusTypes::AUTHORIZING);
    \expect($next)->toBeInstanceOf(RedirectActionInterface::class);
    \expect($next->getType())->toBe(AuthorizationFlowActionTypes::REDIRECT);
    \expect($next->getUri())->toBeString();

    return $created;
})->depends('it starts payment authorization');

\it('retrieves payment as authorizing - redirect', function (PaymentCreatedInterface $created) {
    /** @var PaymentAuthorizingInterface $payment */
    $payment = $created->getDetails();

    \expect($payment)->toBeInstanceOf(PaymentAuthorizingInterface::class);
    \expect($payment->getAuthorizationFlowNextAction())->toBeInstanceOf(RedirectActionInterface::class);
    \expect($payment->getAuthorizationFlowConfig())->toBeInstanceOf(ConfigurationInterface::class);
    \expect($payment->getMetadata())->toMatchArray([
        'metadata_key_1' => 'metadata_value_1',
        'metadata_key_2' => 'metadata_value_2',
        'metadata_key_3' => 'metadata_value_3',
    ]);

    return $created;
})->depends('it submits provider');

\it('executes payment', function (PaymentCreatedInterface $created) {
    /** @var RedirectActionInterface $next */
    $next = $created->getDetails()->getAuthorizationFlowNextAction();

    \bankAction($next->getUri(), 'Execute');
    \sleep(10);

    /* @var PaymentExecutedInterface $payment */
    $payment = $created->getDetails();

    \expect($payment)->toBeInstanceOf(PaymentExecutedInterface::class);
    \expect($payment->getAuthorizationFlowConfig())->toBeInstanceOf(ConfigurationInterface::class);
    \expect($payment->getAuthorizationFlowConfig()->getRedirectReturnUri())->toBeString();
    \expect($payment->getExecutedAt())->toBeInstanceOf(DateTimeInterface::class);

    return $created;
})->depends('it submits provider');

\it('creates payment and fails authorization', function () {
    $created = \paymentHelper()->create();
    $created->startAuthorization('https://console.truelayer.com/redirect-page');
    \client()->submitPaymentProvider($created, 'mock-payments-gb-redirect');

    /** @var RedirectActionInterface $next */
    $next = $created->getDetails()->getAuthorizationFlowNextAction();

    \bankAction($next->getUri(), 'RejectExecution');
    \sleep(10);

    /** @var PaymentFailedInterface $payment */
    $payment = $created->getDetails();

    \expect($payment)->toBeInstanceOf(PaymentFailedInterface::class);
    \expect($payment->getFailedAt())->toBeInstanceOf(DateTimeInterface::class);
    \expect($payment->getFailureStage())->toBeString();
    \expect($payment->getFailureReason())->toBeString();
});
