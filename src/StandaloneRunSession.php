<?php
declare(strict_types=1);

final class StandaloneRunSession
{
    private array $session;
    private StandaloneDepersonalizer $service;

    public function __construct(array &$session, StandaloneDepersonalizer $service)
    {
        $this->session =& $session;
        $this->service = $service;
    }

    public function start(array $options, string $binding): array
    {
        if (isset($this->session['depersonalizer_run']) && !$this->session['depersonalizer_run']['finished']) {
            $previous = $this->session['depersonalizer_run'];
            if (hash_equals($previous['binding'], $binding) && !$previous['plan']['options']['dry_run']) {
                // PHP holds the session lock: the previous request has finished its batch.
                $this->service->finishRun($previous['plan']['lease_id']);
            }
            $this->session['depersonalizer_run']['finished'] = true;
        }
        $plan = $this->service->createRun($options);
        $this->session['depersonalizer_run'] = array(
            'plan' => $plan, 'binding' => $binding, 'next_batch' => 0,
            'last_response' => null, 'finished' => false,
        );
        return array('run_id' => $plan['run_id'], 'cutoff' => $plan['cutoff'],
            'total' => array_sum($plan['totals']), 'next_batch' => 0);
    }

    public function batch(string $runId, int $batch, string $binding): array
    {
        $this->check($runId, $binding);
        $run =& $this->session['depersonalizer_run'];
        if ($batch === $run['next_batch'] - 1 && $run['last_response'] !== null) {
            return $run['last_response'];
        }
        if ($run['finished'] || $batch !== $run['next_batch']) {
            throw new RuntimeException('Неверный номер пакета или запуск уже завершен.');
        }
        $result = $this->service->runBatch($run['plan']);
        $run['plan'] = $result['plan'];
        unset($result['plan']);
        $result['next_batch'] = ++$run['next_batch'];
        $run['finished'] = $result['done'];
        $run['last_response'] = $result;
        return $result;
    }

    public function stop(string $runId, string $binding): void
    {
        $this->check($runId, $binding);
        $run =& $this->session['depersonalizer_run'];
        if (!$run['plan']['options']['dry_run']) {
            $this->service->finishRun($run['plan']['lease_id']);
        }
        $run['finished'] = true;
    }

    private function check(string $runId, string $binding): void
    {
        $run = $this->session['depersonalizer_run'] ?? null;
        if (!$run || !hash_equals($run['plan']['run_id'], $runId) || !hash_equals($run['binding'], $binding)) {
            throw new RuntimeException('Запуск не найден либо изменились настройки подключения или доступа. Создайте новый запуск.');
        }
    }
}
