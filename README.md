# Certify LMS

マルチ資格対応の資格学習プラットフォームです。受講生は資格ごとの教材で学習し、演習問題・模擬試験で理解度を確かめながら、コーチの面談サポートを受けて資格取得を目指せます。

> プロジェクト構造・ドメインモデル・コードの読み進め方は [ONBOARDING.md](./ONBOARDING.md) を参照してください。

## 主な機能

| ロール | 機能 |
|---|---|
| 受講生（student） | 教材閲覧 / 演習問題・苦手分野ドリル / 模擬試験（分野別ヒートマップ・合格可能性スコア）/ 面談予約 / チャット / 学習時間・進捗・ストリーク管理 / 修了証の受領 |
| コーチ（coach） | 教材・演習問題・模試の管理 / 担当受講生の進捗フォロー / 面談対応・面談メモ / チャット |
| 管理者（admin） | ユーザー招待・管理 / 資格・資格分類マスタ管理 / 資格へのコーチ割当 / 面談回数の付与 / 全体ダッシュボード |

## 動作環境

- Docker Desktop / Docker Compose
- 開発環境は Laravel Sail で構築します（PHP コンテナ・MySQL・Mailpit・phpMyAdmin を起動）

## 環境構築手順

### 1. リポジトリの clone

```bash
git clone <このリポジトリの URL>
cd <リポジトリ名>
```

### 2. 環境変数ファイルの作成

```bash
cp .env.example .env
```

`.env.example` は Sail 向けに設定済みのため、コピーするだけでローカル開発を始められます（外部サービス連携のキーは後述）。

### 3. 依存パッケージのインストール（初回のみ）

`vendor/` がまだ無いため、初回のみ Docker 経由で Composer を実行します。

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

### 4. Sail エイリアスの設定（推奨）

```bash
alias sail='./vendor/bin/sail'
```

以降のコマンドはこのエイリアス前提で記載します（未設定の場合は `./vendor/bin/sail` に読み替えてください）。

### 5. コンテナの起動

```bash
sail up -d
```

### 6. アプリケーションの初期化

```bash
sail artisan key:generate
sail artisan storage:link
sail artisan migrate:fresh --seed
```

`storage:link` は教材画像・プロフィール画像の配信に必要です。`migrate:fresh --seed` でテーブル作成とデモデータ投入が行われます（いつでも再実行してデータを初期状態に戻せます）。

### 7. フロントエンドのビルド

```bash
sail npm install
sail npm run build
```

Blade / CSS / JS を編集しながら開発する場合は、`build` の代わりに `sail npm run dev` を起動したままにしてください（Vite のホットリロードが効きます）。

### 8. 動作確認

http://localhost:8000 にアクセスし、下記の[ログインアカウント](#ログインアカウント)でログインできればセットアップ完了です。

## 開発環境 URL

| 用途 | URL |
|---|---|
| アプリケーション | http://localhost:8000 |
| phpMyAdmin（DB 確認） | http://localhost:8080 |
| Mailpit（メール確認） | http://localhost:8025 |

アプリケーションが送信するメール（招待メールなど）はすべて Mailpit に届きます。実際のメールは送信されません。

## ログインアカウント

`migrate:fresh --seed` 後、以下の固定アカウントが使えます（パスワードはすべて `password`）。

| ロール | メールアドレス | 備考 |
|---|---|---|
| 管理者 | admin@certify-lms.test | 全機能にアクセス可能 |
| コーチ | coach@certify-lms.test | IT 系資格の担当 |
| コーチ | coach2@certify-lms.test | ビジネス系資格の担当 |
| 受講生 | student@certify-lms.test | 受講中の資格・学習履歴・面談などのデモデータ付き |

このほか、ライフサイクル（招待中 / 受講中 / 卒業 / 退会）を網羅したデモユーザーが投入されます。

> 本サービスは**招待制**です。公開の会員登録画面はありません。新規ユーザーを作るには、管理者でログイン → ユーザー管理から招待 → Mailpit で招待メールの URL を開く → オンボーディング登録、という流れになります。

## S-B-09 面談リマインダー通知の動作確認

この手順は、S-B-09「面談リマインダー通知（前日・1時間前）」をローカル環境で再現確認するためのものです。

既存の環境構築手順は[開発環境のセットアップ](#開発環境のセットアップ)を、ログイン用メールアドレスとパスワードは[ログインアカウント](#ログインアカウント)を参照してください。この節ではアカウント情報を重複記載しません。

この手順はローカル環境専用です。既存のUser・Enrollment・Meetingを作成、更新、削除せず、確認用のdispatchと通知だけを追加します。開発環境以外では実行しないでください。`migrate`、`migrate:fresh`、データベースの初期化、Docker volumeの削除は行いません。

### STEP 1：動作確認の準備

実行場所：VS Codeのターミナル。

プロジェクトルートでSailを起動します。Sailがすでに起動している場合は、再実行不要です。

```bash
sail up -d
```

次のコマンドで、ローカル開発環境とMailpit接続先を確認します。

```bash
sail artisan tinker
```

Tinkerに入ったら、次をそのまま貼り付けます。

```php
echo json_encode([
    'APP_ENV' => app()->environment(),
    'DB_DATABASE' => env('DB_DATABASE'),
    'MAIL_HOST' => env('MAIL_HOST'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
```

期待結果は次のとおりです。

```text
{"APP_ENV":"local","DB_DATABASE":"certify_lms","MAIL_HOST":"mailpit"}
```

異なる値が表示された場合は、Commandを実行せず、設定を確認してください。確認後、Tinkerで`exit`を入力して終了します。

### STEP 2：確認対象の面談を調べる

実行場所：VS Codeのターミナルから起動したTinker。

READMEの[ログインアカウント](#ログインアカウント)にある受講生とコーチを使い、通知の候補になる`reserved`状態のMeetingを検索します。実際の現在日時は条件に含めません。後のSTEPでMeetingの開始日時を基準にCarbonの時刻を固定し、その固定時刻にCommandの対象になるかを再確認します。過去日時のMeetingも候補に含まれます。次のコードはMeetingを変更しません。

```bash
sail artisan tinker
```

```php
$student = App\Models\User::query()
    ->where('email', 'student@certify-lms.test')
    ->where('role', App\Enums\UserRole::Student->value)
    ->where('status', App\Enums\UserStatus::InProgress->value)
    ->whereNull('deleted_at')
    ->first();
$coach = App\Models\User::query()
    ->where('email', 'coach@certify-lms.test')
    ->where('role', App\Enums\UserRole::Coach->value)
    ->where('status', App\Enums\UserStatus::InProgress->value)
    ->whereNull('deleted_at')
    ->first();

if ($student === null || $coach === null) {
    throw new RuntimeException('StudentまたはCoachが見つからないため停止します。');
}

$meetings = App\Models\Meeting::query()
    ->where('student_id', $student->id)
    ->where('coach_id', $coach->id)
    ->where('status', App\Enums\MeetingStatus::Reserved->value)
    ->orderBy('scheduled_at')
    ->get(['id', 'enrollment_id', 'scheduled_at', 'status', 'topic']);

$meetings = $meetings->filter(function ($meeting) use ($student): bool {
    return App\Models\Enrollment::query()
        ->whereKey($meeting->enrollment_id)
        ->where('user_id', $student->id)
        ->exists();
});

$meetings->each(fn ($meeting) => print(json_encode([
    'id' => $meeting->id,
    'scheduled_at' => $meeting->scheduled_at->timezone('Asia/Tokyo')->format('Y-m-d H:i:s'),
    'status' => $meeting->status->value,
    'topic' => $meeting->topic,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL));
```

出力されたMeeting IDと開始日時を記録してください。IDや日時をREADMEに固定値で書きません。Seederを再実行する必要はありません。Student・Coachが`in_progress`かつSoftDeleteなし、EnrollmentがStudentに紐づくこと、Meetingが`reserved`であることをこの検索で確認しています。

前日通知は同じ対象日の全Meetingが対象になるため、実行前に次の確認を行います。1件だけを確認したい場合は、対象日の他の`reserved` Meetingがない日時を選んでください。複数件が表示された場合は、対象範囲を確認するまで次へ進まないでください。対象が0件の場合も、既存データを変更せず停止してください。

```php
$meetings->count();
```

対象が0件の場合は、既存Meetingの日時・状態を変更せず、ここで停止してください。1時間前通知は開始時刻の1分間だけを対象にするため、後述の実行時刻で対象Meetingが1件になることを確認します。

### STEP 3：前日18:00の通知を送信する

実行場所：同じTinkerプロセス。

STEP 2で確認したMeetingから、前日通知を確認したい1件を選びます。次のコードは開始日時から実行時刻を動的に計算するため、Meeting IDや日時を固定しません。`$target`が1件でない場合は例外で停止し、Commandを実行しません。

```php
$target = $meetings->first();

if ($target === null) {
    throw new RuntimeException('確認対象Meetingがないため停止します。');
}

$targetDate = Carbon\CarbonImmutable::instance($target->scheduled_at)->setTimezone('Asia/Tokyo');
$eveNow = $targetDate->subDay()->setTime(18, 0, 30);
$eveTargets = App\Models\Meeting::query()
    ->where('status', App\Enums\MeetingStatus::Reserved->value)
    ->where('scheduled_at', '>=', $eveNow->startOfDay()->addDay()->format('Y-m-d H:i:s'))
    ->where('scheduled_at', '<', $eveNow->startOfDay()->addDays(2)->format('Y-m-d H:i:s'))
    ->where('scheduled_at', '>', $eveNow->format('Y-m-d H:i:s'))
    ->orderBy('id')
    ->get(['id', 'scheduled_at']);

$eveTargets->each(fn ($meeting) => print($meeting->id.' '.$meeting->scheduled_at->timezone('Asia/Tokyo')->format('Y-m-d H:i:s').PHP_EOL));

if ($eveTargets->count() !== 1 || $eveTargets->first()->id !== $target->id) {
    throw new RuntimeException('前日通知の対象が1件ではないため停止します。');
}

try {
    Carbon\CarbonImmutable::setTestNow($eveNow);
    $exitCode = Artisan::call('notifications:send-meeting-reminders', ['--window' => 'eve']);
    echo 'exit_code='.$exitCode.PHP_EOL;
    echo Artisan::output();
} finally {
    Carbon\CarbonImmutable::setTestNow();
}
```

実装上のCommand名は`notifications:send-meeting-reminders`です。期待結果は`exit_code=0`で、対象Meetingのstudent・coachそれぞれについてdispatchが取得されます。Command出力の`acquired`、`duplicate`、`failed`を記録してください。`failed`が0でない場合は次へ進まず、出力とログを確認します。

### STEP 4：ブラウザ・Mailpitで通知を確認する

実行場所：ブラウザ。

1. [ログインアカウント](#ログインアカウント)の受講生で`http://localhost:8000`にログインします。
2. ヘッダーの通知ベル、または`/notifications`を開きます。
3. 「面談リマインダー」の前日通知が表示されることを確認します。
4. 通知を開き、対象Meetingの詳細画面へ遷移できることを確認します。
5. ログアウトし、コーチアカウントで同じ確認を行います。
6. `http://localhost:8025`を開き、受講生宛とコーチ宛のメールがそれぞれ届いていることを確認します。

スクリーンショットには、通知一覧、Meeting詳細画面、Mailpitの対象メールが分かる状態を含めてください。既存通知が表示されている場合は、今回の対象Meetingの日時・内容であることを確認し、既存通知を削除しないでください。

### STEP 5：開始1時間前の通知を送信・確認する

実行場所：STEP 3と同じTinkerプロセス、確認はブラウザとMailpit。

STEP 3の`$target`を使い、開始1時間前の1分間に時刻を固定します。対象Meetingが1件であることを確認してからCommandを実行します。

```php
$oneHourNow = Carbon\CarbonImmutable::instance($target->scheduled_at)
    ->setTimezone('Asia/Tokyo')
    ->subHour()
    ->setSecond(30);
$oneHourTargets = App\Models\Meeting::query()
    ->where('status', App\Enums\MeetingStatus::Reserved->value)
    ->where('scheduled_at', '>=', $oneHourNow->startOfMinute()->addHour()->format('Y-m-d H:i:s'))
    ->where('scheduled_at', '<', $oneHourNow->startOfMinute()->addHour()->addMinute()->format('Y-m-d H:i:s'))
    ->where('scheduled_at', '>', $oneHourNow->format('Y-m-d H:i:s'))
    ->orderBy('id')
    ->get(['id', 'scheduled_at']);

$oneHourTargets->each(fn ($meeting) => print($meeting->id.' '.$meeting->scheduled_at->timezone('Asia/Tokyo')->format('Y-m-d H:i:s').PHP_EOL));

if ($oneHourTargets->count() !== 1 || $oneHourTargets->first()->id !== $target->id) {
    throw new RuntimeException('1時間前通知の対象が1件ではないため停止します。');
}

try {
    Carbon\CarbonImmutable::setTestNow($oneHourNow);
    $exitCode = Artisan::call('notifications:send-meeting-reminders', ['--window' => 'one_hour_before']);
    echo 'exit_code='.$exitCode.PHP_EOL;
    echo Artisan::output();
} finally {
    Carbon\CarbonImmutable::setTestNow();
}
```

期待結果は`exit_code=0`で、`failed=0`です。Student・Coachの通知一覧、対象Meeting詳細への遷移、Mailpitの各メールを確認します。

### STEP 6：重複送信防止を確認する

実行場所：TinkerとMailpit。

同じwindowを再実行すると、複合一意制約により同じMeeting・受信者へのdispatchは`duplicate`になります。Student・Coachの通知件数とMailpitのメール件数が増えないことを確認します。

STEP 3またはSTEP 5と同じコードを、同じ固定時刻・同じwindowで1回だけ再実行してください。再実行前に、次の読み取り確認でdispatch件数を記録します。

```php
$dispatchCountBefore = App\Models\MeetingReminderDispatch::query()
    ->where('meeting_id', $target->id)
    ->count();
$notificationCountBefore = DB::table('notifications')
    ->where('type', App\Notifications\MeetingReminderNotification::class)
    ->whereIn('notifiable_id', [$student->id, $coach->id])
    ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.meeting_id'))"), [$target->id])
    ->count();
echo json_encode([
    'dispatches' => $dispatchCountBefore,
    'notifications' => $notificationCountBefore,
], JSON_UNESCAPED_UNICODE).PHP_EOL;
```

再実行後は同じSELECTを実行し、dispatch・database通知の増分が0件であることを確認します。Mailpitでも対象メールが増えていないことを確認してください。増分が発生した場合は、追加のCommandを実行せず、確認を停止してください。

### STEP 7：確認用データの後片付け（任意）

今回の手順では既存User・Enrollment・Meetingを利用しているため、User・Enrollment・Meetingは絶対に削除しません。後片付けは省略可能です。採点証跡を残す場合は、dispatch・database通知・Mailpitメールをそのまま保持してください。

後片付けを行う場合は、STEP 3とSTEP 5の実行前に、対象Meeting・Student・Coachについて既に存在したdispatch IDと通知IDを記録してください。実行後に新たに作成されたIDを、実行前のIDとの差分として特定できない場合は削除せず停止してください。件数だけを条件にした削除や、Meeting ID・受信者IDだけを条件にした一括削除は、既存通知を削除する可能性があるため行わないでください。

安全に新規IDを記録できない場合は、後片付けを省略してください。Mailpitのメールを削除する場合も、アプリケーションDBとは別にMailpit画面で今回の確認メールだけを手動選択します。既存の通知、dispatch、User、Enrollment、Meetingは削除しません。

## テスト

```bash
sail artisan test                  # 全テスト実行
sail artisan test --filter=Xxx    # クラス名・メソッド名で絞り込み
```

## コード整形

Laravel Pint を使用しています。コミット前に実行してください。

```bash
sail bin pint --dirty    # 変更ファイルのみ整形
sail bin pint --test     # 整形漏れの確認（CI 相当のチェック）
```

## 使用技術

- PHP 8.5 / Laravel 10
- MySQL 8.4
- Laravel Fortify（認証）/ Laravel Sanctum（API 認証）
- Blade + Tailwind CSS + Vite（JavaScript は素の JS、フレームワーク不使用）
- PHPUnit / Laravel Pint
- league/commonmark（教材本文の Markdown レンダリング）
- Pusher（チャットのリアルタイム配信）
- Docker（Laravel Sail）

## 環境変数

`.env.example` をコピーするだけで、すべての機能がローカルで動作します（メールは Mailpit に配信されます）。

- `PUSHER_*` — チャットのリアルタイム配信に使用します。有効にする場合は Pusher のキーを取得して設定し、`BROADCAST_DRIVER=pusher` に変更してください。未設定（既定の `BROADCAST_DRIVER=log`）でもメッセージの送受信自体は動作し、相手画面へのリアルタイム反映のみ行われません

新しい環境変数やセットアップ手順を追加した場合は、`.env.example` と本 README に追記し、チームの誰でも環境を再現できる状態を保ってください。
