}

.area-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.area-item {
    border: 1px solid #E2E8F0;
    border-radius: 14px;
    padding: 12px;
    background: #FCFDFE;
}

.area-item.ok { border-left: 4px solid #10B981; }
.area-item.warn { border-left: 4px solid #F59E0B; }
.area-item.bad { border-left: 4px solid #EF4444; }

.area-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.area-percent {
    font-weight: 700;
}

.area-metrics {
    margin-top: 6px;
    display: flex;
    gap: 12px;
    font-size: 12px;
    color: #64748B;
}

.area-progress {
    margin-top: 8px;
    width: 100%;
    height: 9px;
    border-radius: 999px;
    background: #E2E8F0;
    overflow: hidden;
}

.area-progress span {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #22C55E 0%, #06B6D4 100%);
}

.alert-cards {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.alert-card {
    display: flex;
    align-items: center;
    gap: 10px;
    border-radius: 12px;
    padding: 10px;
    border: 1px solid #E2E8F0;
    background: #F8FAFC;
}

.alert-card.ok { border-left: 4px solid #10B981; }
.alert-card.warn { border-left: 4px solid #F59E0B; }
.alert-card.bad { border-left: 4px solid #EF4444; }
.alert-card.info { border-left: 4px solid #3B82F6; }

.alert-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #E2E8F0;
    font-weight: 700;
}

.alert-title {
    font-size: 12px;
    color: #64748B;
}

.alert-value {
    font-size: 14px;
    font-weight: 700;
    color: #0F172A;
}

.ops-row-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.rank-panel {
    border-radius: 18px;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
}

.rank-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.rank-item {
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    padding: 10px;
    background: #FCFDFE;
}

.rank-pos {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #E2E8F0;
    font-size: 12px;
    font-weight: 700;
}

.rank-main {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.rank-main strong {
    font-size: 14px;
    color: #0F172A;
}

.rank-main small {
    font-size: 12px;
    color: #64748B;
}

.rank-score {
    font-size: 13px;
    font-weight: 700;
    color: #059669;
}

.rank-score.danger {
    color: #DC2626;
}

.best-worker .card-body {
    display: flex;
    align-items: center;
    justify-content: center;
}

.best-worker-block {
    text-align: center;
}

.best-avatar {
    width: 56px;
    height: 56px;
    margin: 0 auto;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: #0F172A;
    background: linear-gradient(135deg, rgba(25, 211, 197, 0.2), rgba(79, 140, 255, 0.2));
}

.best-worker-block h3 {
    margin-top: 10px;
    font-size: 18px;
    color: #0F172A;
}

.best-worker-block p {
    margin-top: 4px;
    color: #64748B;
    font-size: 13px;
}

.best-metrics {
    margin-top: 12px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.best-metrics div {
    border: 1px solid #E2E8F0;
    border-radius: 10px;
    padding: 8px;
    background: #F8FAFC;
}

.best-metrics span {
    display: block;
    font-size: 11px;
    color: #64748B;
}

.best-metrics strong {
    display: block;
    font-size: 18px;
    color: #0F172A;
}

@media (max-width: 1199px) {
    .ops-kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .ops-row-3 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 991px) {
    .ops-row-2 {
        grid-template-columns: 1fr;
    }

    .ops-hero {
        flex-direction: column;
    }

    .ops-hero-actions {
        width: 100%;
    }
}

@media (max-width: 767px) {
    .ops-kpi-grid,
    .ops-row-3,
    .ops-hero-stats {
        grid-template-columns: 1fr;
    }

    .area-metrics {
        flex-wrap: wrap;
    }

    .ops-hero-title {
        font-size: 24px;
    }

    .ops-hero {
        padding: 18px;
    }

    .ops-hero-actions .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>
@endpush
