<?php

declare(strict_types=1);

use Pediacare\Core\Container;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function test_T03_factory_is_lazy_and_instance_is_shared(): void
    {
        $container = new Container();
        $calls = 0;
        $container->register('service', static function (Container $container) use (&$calls): object {
            $calls++;

            return new stdClass();
        });

        self::assertSame(0, $calls);
        $instance = $container->get('service');
        self::assertSame($instance, $container->get('service'));
        self::assertSame(1, $calls);
    }

    public function test_T03_factories_can_receive_shared_dependencies(): void
    {
        $container = new Container();
        $container->register('dependency', static fn (Container $container): object => new stdClass());
        $container->register('service', static fn (Container $container): object => (object) [
            'dependency' => $container->get('dependency'),
        ]);

        self::assertSame($container->get('dependency'), $container->get('service')->dependency);
    }

    public function test_T03_unknown_service_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Service is not registered.');

        (new Container())->get('unknown');
    }

    public function test_T03_replacing_a_factory_discards_its_cached_instance(): void
    {
        $container = new Container();
        $container->register('service', static fn (Container $container): object => new stdClass());
        $original = $container->get('service');
        $replacement = new stdClass();
        $container->register('service', static fn (Container $container): object => $replacement);

        self::assertSame($replacement, $container->get('service'));
        self::assertNotSame($original, $container->get('service'));
    }
}
