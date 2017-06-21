<?php

namespace core;

use yii\log\Target;


class ShowTarget extends Target
{
    // 不记录日志，输出到屏幕
    public function export()
    {
        $text = implode("\n", array_map([$this, 'formatMessage'], $this->messages)) . "\n";
        echo $text;
    }
}
