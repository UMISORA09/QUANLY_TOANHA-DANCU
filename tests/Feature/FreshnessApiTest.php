<?php

namespace Tests\Feature;

use Tests\TestCase;

class FreshnessApiTest extends TestCase
{
    /**
     * Kiểm tra endpoint GET /api/monitoring/freshness trả về cấu trúc chuẩn
     */
    public function test_freshness_endpoint_returns_json_with_required_structure(): void
    {
        $response = $this->getJson('/api/monitoring/freshness');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'overall_state',
            'checked_at',
            'collector' => [
                'status',
                'age_seconds',
                'warning_threshold',
                'critical_threshold',
            ],
            'github_actions' => [
                'status',
                'warning_threshold',
                'critical_threshold',
            ],
            'deployment' => [
                'status',
                'warning_threshold',
                'critical_threshold',
            ],
            'application_health' => [
                'status',
                'warning_threshold',
                'critical_threshold',
            ],
            'database' => [
                'status',
                'sources',
            ],
            'incidents',
        ]);
    }

    /**
     * Kiểm tra endpoint GET /metrics xuất đầy đủ các metrics Prometheus về Freshness
     */
    public function test_metrics_endpoint_exports_freshness_prometheus_gauges(): void
    {
        $response = $this->get('/metrics');

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('collector_data_age_seconds', $content);
        $this->assertStringContainsString('collector_last_success_timestamp', $content);
        $this->assertStringContainsString('collector_errors_total', $content);
        $this->assertStringContainsString('freshness_age_seconds', $content);
        $this->assertStringContainsString('freshness_source_timestamp_seconds', $content);
        $this->assertStringContainsString('freshness_status_binary', $content);
    }

    /**
     * Kiểm tra endpoint alias GET /api/admin/cicd/freshness hoạt động đồng nhất
     */
    public function test_admin_cicd_freshness_alias_endpoint(): void
    {
        $response = $this->getJson('/api/admin/cicd/freshness');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'overall_state',
            'checked_at',
            'collector',
            'database',
        ]);
    }
}
