# T-A-01 改修後SQL分類訂正

## 訂正理由

既存の計測ハーネスは、更新者関連SQLを `where users.id =` の形式だけで分類していました。

Eager Loading後の更新者取得は `where users.id in (...)` の一括取得になったため、実際には更新者関連SQLであるSQLが「その他SQL」に分類されていました。

## 実際に発行された一括取得SQL

資格の一括取得SQL：

```sql
select * from `certifications` where `certifications`.`id` in (?)
```

更新者の一括取得SQL：

```sql
select * from `users` where `users`.`id` in (?, ?) and `users`.`deleted_at` is null
```

資格フィルタ選択肢の取得SQLは次のとおりです。

```sql
select * from `certifications` order by `name` asc
```

## 訂正前後の分類

| 条件 | SQL総数 | 訂正前の資格関連 | 訂正前の更新者関連 | 訂正前のその他 | 訂正後の資格関連 | 訂正後の更新者関連 | 訂正後のその他 |
|---|---:|---:|---:|---:|---:|---:|---:|
| 管理者・5件 | 7 | 2 | 0 | 5 | 2 | 1 | 4 |
| 管理者・20件 | 7 | 2 | 0 | 5 | 2 | 1 | 4 |
| 管理者・21件以上・2ページ目 | 7 | 2 | 0 | 5 | 2 | 1 | 4 |
| コーチ・担当資格・2ページ目 | 7 | 2 | 0 | 5 | 2 | 1 | 4 |

SQL総数7件は訂正前後で変わりません。更新者関連SQL1件は模試行ごとの遅延取得ではなく、`IN (...)` による一括取得です。

## N+1判定への影響

分類条件の誤りを訂正したものであり、SQL総数や実際のSQL発行内容は変わりません。

改修後は、5件・20件のいずれでも更新者関連SQLが1件であり、模試行数に比例して増加していません。資格関連SQLも2件で一定です。したがって、N+1解消の判定に影響はありません。

## 問題数表示のHTTPレスポンス検証

一時プローブ `tests/Temp/Ta01VerificationProbeTest.php` を使用し、問題0件の模試と問題1件の模試を含む一覧HTTPレスポンスを確認しました。

- 問題0件の模試：`0` 表示
- 問題1件の模試：`0` 表示
- 問題数属性：取得なし
- `mock_exam_questions` を参照するSQL：0件
- `withCount('mockExamQuestions')`：未追加
- View：未変更
