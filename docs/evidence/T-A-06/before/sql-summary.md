# T-A-06 修正前SQL計測サマリー

実施日: 2026-10-23 (Asia/Tokyo)
ブランチ: feature/T-A-06

## 計測できた事実

- `DashboardQueryCountTest`は成功した。
- 結果は6 passed (12 assertions)。
- 管理者Dashboardのクエリ数上限テストも成功した。
- 同テストは`DB::listen()`でクエリ数を数えるが、初回アクセスと連続アクセスの集計SQLを直接比較しない。
- `AdminDashboardCacheTest`のキャッシュヒット2件は失敗し、状態変更後の無効化期待2件は成功した。

## 追加計測結果（2026-10-23）

Sail内の一時的なTinker実行で`DB::listen()`を登録し、同一`EnrollmentStatsService`インスタンスで各集計メソッドを2回呼び出した。Enrollmentの変更は行っていない。

| 対象 | 初回集計SQL | 2回目集計SQL | 初回時間(ms) | 2回目時間(ms) | 結果一致 |
|---|---:|---:|---:|---:|---|
| `adminKpi()` | 2 | 2 | 15.39 | 0.35 | true |
| `completionRateByCertification()` | 1 | 1 | 0.13 | 0.13 | true |

実行環境は`APP_ENV=local`、MySQLの`certify_lms`、database cacheである。これはPHPUnitのtesting環境ではなく、課題用開発DBを読み取った計測である。

## 実測していない数値

- 管理者Dashboard HTTPリクエスト全体の初回・連続アクセスSQL本数
- キャッシュストアを経由した場合のSQL本数（現行Serviceを直接呼び出したため）

したがって、修正前の「初回と連続アクセスのSQL回数差」は未計測である。

## 次回の計測方法

既存テストコードを変更せず、専用の読み取り用計測手段または既存の`DB::listen()`パターンを使い、初回・連続アクセスを別々に記録する必要がある。今回の証跡取得ではコード追加を行っていない。
