# T-A-06 修正後SQL計測サマリー

実施日: 2026-10-23 (Asia/Tokyo)
ブランチ: feature/T-A-06

## Before / After比較

| 対象 | Before 初回 | Before 2回目 | After 初回 | After 2回目 |
|---|---:|---:|---:|---:|
| `adminKpi()` | 2（before証跡） | 2（before証跡） | 2（実測） | 0（実測） |
| `completionRateByCertification()` | 1（before証跡） | 1（before証跡） | 1（実測） | 0（実測） |

## 確認できた事実

- 修正前はKPI 2本、修了率1本が2回目にも実行されていた。
- 修正後はCache missの初回にKPI 2本、修了率1本の集計SQLが実行された。
- 修正後はCache hitの2回目取得で、KPI・修了率とも集計SQLが0本だった。
- KPI・修了率とも初回と2回目の返却値が一致した。
- TTL満了後は再集計され、変更後の集計結果が返った。
- TTL満了だけでは共通世代番号は変わらなかった。
- Cache障害・世代競合・Transactionの関連テストは成功した。
