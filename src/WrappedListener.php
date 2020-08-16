<?php

declare(strict_types=1);

/*
 * This file is part of BiuradPHP opensource projects.
 *
 * PHP version 7.2 and above required
 *
 * @author    Divine Niiquaye Ibok <divineibok@gmail.com>
 * @copyright 2019 Biurad Group (https://biurad.com/)
 * @license   https://opensource.org/licenses/BSD-3-Clause License
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BiuradPHP\Events;

use Closure;
use Psr\EventDispatcher\StoppableEventInterface;
use ReflectionFunction;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use TypeError;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
final class WrappedListener
{
    private $listener;

    private $name;

    private $optimizedListener;

    private $called;

    private $stoppedPropagation;

    private $dispatcher;

    private $duration;

    private $pretty;

    private $priority;

    /**
     * @param callable                 $listener
     * @param null|string              $name
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct($listener, ?string $name, EventDispatcherInterface $dispatcher = null)
    {
        if (\is_callable($listener)) {
            $listener = Closure::fromCallable($listener);
        }

        $this->listener           = $listener;
        $this->optimizedListener  = $listener instanceof Closure ? $listener : null;

        $this->dispatcher         = $dispatcher;
        $this->called             = false;
        $this->stoppedPropagation = false;

        $this->trackEventListener($listener);

        if (null !== $name) {
            $this->name = $name;
        }
    }

    public function __invoke(object $event, string $eventName, EventDispatcherInterface $dispatcher): void
    {
        $dispatcher = $this->dispatcher ?? $dispatcher;

        $this->called   = true;
        $this->priority = $dispatcher->getListenerPriority($eventName, $this->listener);
        $timeStart      = \microtime(true);

        try {
            ($this->optimizedListener ?? $this->listener)($event, $eventName, $dispatcher);
        } catch (TypeError $e) {
            if (!$this->dispatcher instanceof TraceableEventDispatcher) {
                throw $e;
            }

            if ($dispatcher instanceof LazyEventDispatcher) {
                $dispatcher->getResolver()->call(
                    $this->optimizedListener ?? $this->listener,
                    [$event, $eventName, EventDispatcherInterface::class => $dispatcher]
                );
            } else {
                ($this->optimizedListener ?? $this->listener)($event, $eventName, $dispatcher);
            }
        }

        $this->duration = \number_format((\microtime(true) - $timeStart) * 1000, 2) . 'ms';

        if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
            $this->stoppedPropagation = true;
        }
    }

    public function getWrappedListener()
    {
        return $this->listener;
    }

    public function wasCalled(): bool
    {
        return $this->called;
    }

    public function stoppedPropagation(): bool
    {
        return $this->stoppedPropagation;
    }

    public function getPretty(): string
    {
        return $this->pretty;
    }

    public function getInfo(string $eventName): array
    {
        if (null !== $this->dispatcher) {
            $priority = $this->dispatcher->getListenerPriority($eventName, $this->listener);
        }
        $priority = null !== $this->priority ? $this->priority : $priority;

        return [
            'event'      => $eventName,
            'priority'   => $priority,
            'duration'   => $this->duration,
            'pretty'     => $this->pretty,
        ];
    }

    /**
     * @param callable $listener
     */
    private function trackEventListener($listener): void
    {
        if (\is_array($listener)) {
            $this->name   = \is_object($listener[0]) ? get_debug_type($listener[0]) : $listener[0];
            $this->pretty = $this->name . '::' . $listener[1];
        } elseif ($listener instanceof Closure) {
            $r = new ReflectionFunction($listener);

            if (false !== \strpos($r->name, '{closure}')) {
                $this->pretty = $this->name = 'closure';
            } elseif ($class = $r->getClosureScopeClass()) {
                $this->name   = $class->name;
                $this->pretty = $this->name . '::' . $r->name;
            } else {
                $this->pretty = $this->name = $r->name;
            }
        } elseif (\is_string($listener)) {
            $this->pretty = $this->name = $listener;
        } else {
            $this->name   = get_debug_type($listener);
            $this->pretty = $this->name . '::__invoke';
        }
    }
}
