<?php
echo "[LOADED CLI FILE]\n"; // для отладки
class shopDepersonalizerCli extends waCliController
{
    // через сколько дней считать «старым» заказ
    protected $days = 365;

    public function execute()
    {
        $this->log('Запуск Depersonalizer (dry-run)');
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$this->days} days"));

        // считаем количество старых заказов
        $m = new shopOrderModel();
        $cnt = $m->query(
            "SELECT COUNT(*) FROM shop_order WHERE create_datetime < s:cutoff",
            ['cutoff' => $cutoff]
        )->fetchField();

        $this->log("Найдено заказов старше {$this->days} дней: $cnt");
    }

    protected function log($msg)
    {
        echo date('[Y-m-d H:i:s] ') . $msg . "\n";
    }
}
