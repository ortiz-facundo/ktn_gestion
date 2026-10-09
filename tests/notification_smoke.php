<?php

declare(strict_types=1);

// Integration smoke checks using the existing dev database. All test records
// live in transactions rolled back below; no PHPUnit dependency is required.
require dirname(__DIR__).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod') !== 'dev') {
    throw new RuntimeException('Notification smoke checks are dev-only.');
}
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
$connection = $em->getConnection();
$router = $container->get('router');
$translator = $container->get('translator');
$creator = new App\Service\Notification\CreateNotification($em, $router);
$inbox = new App\Service\Notification\NotificationInbox($em, $translator, $router);
$subscriptions = new App\Service\Notification\ManagePushSubscriptions($em);
$check = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    print 'PASS '.$message.PHP_EOL;
};
$user = $em->getRepository(App\Entity\Administration\User::class)->findOneBy(['email' => 'test@ketten.local']);
if ($user === null) { throw new RuntimeException('Load development fixtures first.'); }
$other = new App\Entity\Administration\User();
$other->setEmail('notification-smoke-'.bin2hex(random_bytes(6)).'@ketten.local');
$other->setState(App\Entity\Administration\User::USER_STATE_ACTIVE);
$initial = $inbox->count($user);
$initialArchived = $inbox->count($user, archived: true);
$connection->beginTransaction();
try {
    $em->persist($other);
    $em->flush();
    $publicKey = rtrim(strtr(base64_encode("\x04".random_bytes(64)), '+/', '-_'), '=');
    $auth = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    $subscription = $subscriptions->register($user, ['endpoint' => 'https://fcm.googleapis.com/fcm/send/smoke-'.bin2hex(random_bytes(8)), 'keys' => ['p256dh' => $publicKey, 'auth' => $auth]]);
    $notification = $creator->execute($user, 'smoke', 'app.notification.test.title', 'app.notification.test.body', ['%name%' => 'Smoke'], deduplicationKey: 'smoke-test');
    $again = $creator->execute($user, 'smoke', 'app.notification.test.title', 'app.notification.test.body', deduplicationKey: 'smoke-test');
    $check($notification->getId() === $again->getId(), 'same event returns same notification');
    $check($inbox->count($user) === $initial + 1, 'single inbox record');
    $deliveries = $em->getRepository(App\Entity\Notification\NotificationDelivery::class)->findBy(['notification' => $notification, 'pushSubscription' => $subscription]);
    $check(count($deliveries) === 1, 'single per-device delivery');
    try { $inbox->archive($user, $notification->getId()); throw new RuntimeException('Unread notification archived.'); }
    catch (DomainException $e) {
        $check($e->getMessage() === 'app.notification.archive_requires_read' && $em->isOpen(), 'unread archive rejected without invalidating the EntityManager');
    }
    try { $inbox->archive($other, $notification->getId()); throw new RuntimeException('Cross-user archive allowed.'); }
    catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException) { $check(true, 'cross-user archive rejected'); }
    try { $inbox->findOwned($other, $notification->getId()); throw new RuntimeException('Cross-user access allowed.'); }
    catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException) { $check(true, 'cross-user inbox access rejected'); }
    try { $subscriptions->disable($other, $subscription->getId()); throw new RuntimeException('Cross-user revoke allowed.'); }
    catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException) { $check(true, 'cross-user subscription revoke rejected'); }
    $check(!$subscriptions->isAllowedEndpoint('https://127.0.0.1/fcm/send/test') && !$subscriptions->isAllowedEndpoint('https://fcm.googleapis.com.evil.test/fcm/send/test') && !$subscriptions->isAllowedEndpoint('https://user@fcm.googleapis.com/fcm/send/test'), 'untrusted endpoints rejected');
    $inbox->markAsRead($user, $notification->getId());
    $check($notification->getReadAt() !== null, 'read action persisted');
    // MariaDB DATETIME stores whole seconds; compare persisted values rather
    // than the pre-flush PHP timestamp's discarded microseconds.
    $em->refresh($notification);
    $readAt = $notification->getReadAt();
    $deliveryCount = $em->getRepository(App\Entity\Notification\NotificationDelivery::class)->count(['notification' => $notification]);
    $inbox->archive($user, $notification->getId());
    $em->refresh($notification);
    $archivedAt = $notification->getArchivedAt();
    $check($archivedAt !== null && $notification->getReadAt() == $readAt, 'archive preserves read timestamp');
    $check($inbox->count($user) === $initial && $inbox->count($user, archived: true) === $initialArchived + 1, 'archive moves record between inbox and archive counts');
    $check($inbox->present($notification)['archived'] && !$inbox->present($notification)['unread'], 'archived presentation remains read');
    $check(!in_array($notification->getId(), array_map(static fn ($n) => $n->getId(), $inbox->list($user)), true), 'archived notification excluded from default feed');
    $em->refresh($deliveries[0]);
    $check($deliveries[0]->getStatus() === App\Entity\Notification\NotificationDelivery::STATUS_SKIPPED, 'archive cancels pending push delivery');
    $inbox->archive($user, $notification->getId());
    $check($notification->getArchivedAt() == $archivedAt, 'repeated archive preserves archive timestamp');
    try { $inbox->restore($other, $notification->getId()); throw new RuntimeException('Cross-user restore allowed.'); }
    catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException) { $check(true, 'cross-user restore rejected'); }
    $inbox->restore($user, $notification->getId());
    $inbox->restore($user, $notification->getId());
    $check($notification->getArchivedAt() === null && $notification->getReadAt() == $readAt, 'restore is idempotent and keeps original read timestamp');
    $check($inbox->count($user) === $initial + 1 && $inbox->count($user, archived: true) === $initialArchived, 'restored record returns to inbox counts');
    $check($em->getRepository(App\Entity\Notification\NotificationDelivery::class)->count(['notification' => $notification]) === $deliveryCount
        && $em->getRepository(App\Entity\Notification\NotificationDelivery::class)->count(['notification' => $notification, 'status' => App\Entity\Notification\NotificationDelivery::STATUS_PENDING]) === 0,
        'restore never creates or revives push delivery');
    $notification2 = $creator->execute($user, 'smoke.two', 'app.notification.test.title', 'app.notification.test.body', channels: []);
    $inbox->markAllAsRead($user);
    $em->refresh($notification2);
    $check($notification2->getReadAt() !== null, 'mark all read persisted');
    $retry = new App\Entity\Notification\NotificationDelivery($notification, $subscription);
    for ($i = 0; $i < 4; ++$i) { $retry->startAttempt(); $retry->fail('temporary', true); }
    $check($retry->getStatus() === App\Entity\Notification\NotificationDelivery::STATUS_PENDING, 'transient failures remain pending');
    $retry->startAttempt(); $retry->fail('temporary', true);
    $check($retry->getStatus() === App\Entity\Notification\NotificationDelivery::STATUS_FAILED, 'fifth failed attempt is terminal');
    $subscriptions->disable($user, $subscription->getId());
    $em->refresh($deliveries[0]);
    $check($deliveries[0]->getStatus() === App\Entity\Notification\NotificationDelivery::STATUS_SKIPPED, 'device revocation cancels pending delivery');
    $subscription->enable();
    $em->flush();
    $originalToken = new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles());
    $switchToken = new Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken($other, 'main', [], $originalToken);
    $logout = new Symfony\Component\Security\Http\Event\LogoutEvent(Symfony\Component\HttpFoundation\Request::create('/logout', 'POST', cookies: ['notification_device' => (string) $subscription->getId()]), $switchToken);
    $logout->setResponse(new Symfony\Component\HttpFoundation\Response());
    (new App\EventListener\NotificationLogoutListener($subscriptions))($logout);
    $check(!$subscription->isEnabled(), 'impersonated logout revokes actor device, not target devices');
    $id = $notification->getId();
} finally {
    $connection->rollBack();
    $em->clear();
}
$check($em->find(App\Entity\Notification\Notification::class, $id) === null, 'outer transaction rollback removes published notification and queue');
$check($connection->getTransactionNestingLevel() === 0, 'publisher leaves caller transaction boundary intact');
print "Notification integration smoke checks passed.\n";
$kernel->shutdown();
