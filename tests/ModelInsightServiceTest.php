<?php

namespace Tests;

use App\ML\ModelInsightService;
use App\ML\ModelQualityService;

class ModelInsightServiceTest
{
    public function run(TestReporter $t): void
    {
        $mlpWeights = __DIR__ . '/../src/ML/mlp_weights.json';
        $softmaxWeights = __DIR__ . '/../src/ML/model_weights.json';
        $service = new ModelInsightService($mlpWeights, $softmaxWeights);
        $insight = $service->analyze(382.4, 3, 'eco', 'softmax');

        $t->assertEquals('Объяснение использует выбранную A/B-модель', 'softmax', $insight['active_model']);
        $t->assertEquals('Прогноз содержит три класса', 3, count($insight['prediction']['probabilities'] ?? []));
        $t->assertEquals('Сравнение содержит MLP и Softmax', 2, count($insight['comparison']['models'] ?? []));
        $t->assertEquals('Локальная чувствительность рассчитана для двух признаков', 2, count($insight['feature_influence'] ?? []));
        $t->assertEquals('Рейтинг содержит три транспорта', 3, count($insight['ranking']['options'] ?? []));
        $t->assertEquals('Лучший вариант имеет rank=1', 1, $insight['ranking']['options'][0]['rank'] ?? null);
        $t->assertEquals('Найдено пять ближайших примеров', 5, count($insight['nearest_examples'] ?? []));
        $t->assertEquals('Forward pass MLP содержит 8 активаций', 8, count($insight['network']['hidden_activations'] ?? []));
        $t->assertTrue('Объяснение обрабатывает только числовые анонимные признаки', $insight['privacy']['anonymous_features_only'] ?? false);
        $t->assertTrue('Адреса не обрабатываются ML-сервисом', !($insight['privacy']['addresses_processed'] ?? true));

        $quality = (new ModelQualityService($mlpWeights, $softmaxWeights))->report();
        $t->assertEquals('Holdout содержит 20% из 600 примеров', 120, $quality['dataset']['holdout_samples'] ?? null);
        $t->assertEquals('Валидация и финальный test разделены', 60, $quality['dataset']['validation_samples'] ?? null);
        $t->assertEquals('Финальный test содержит 60 примеров', 60, $quality['dataset']['test_samples'] ?? null);
        $t->assertTrue('Accuracy MLP лежит в [0,1]', ($quality['models']['mlp']['metrics']['accuracy'] ?? -1) >= 0 && ($quality['models']['mlp']['metrics']['accuracy'] ?? 2) <= 1);
        $t->assertTrue('Log loss MLP конечен и неотрицателен', is_finite($quality['models']['mlp']['metrics']['log_loss'] ?? INF) && ($quality['models']['mlp']['metrics']['log_loss'] ?? -1) >= 0);
        $t->assertEquals('Отчёт содержит шесть воспроизводимых снимков обучения', 6, count($quality['training']['models']['mlp']['snapshots'] ?? []));
        $t->assertTrue('Снимки обучения связаны с активными весами обеих моделей по SHA-256', $quality['training']['matches_active_model'] ?? false);
        $t->assertEquals('Cross-validation загружена из воспроизводимого отчёта обучения', 5, $quality['cross_validation']['folds'] ?? null);
        $t->assertEquals('Model Card показывает активную версию MLP', $quality['models']['mlp']['version'] ?? null, $quality['model_card']['version'] ?? null);
        $t->assertTrue('Model Card явно запрещает мгновенную мутацию production', !($quality['release_policy']['single_feedback_mutates_production'] ?? true));
    }
}
