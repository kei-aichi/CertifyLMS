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

### Google Calendar連携（S-A-01）

この機能はSailと既存のデモデータを使って確認できます。まず[環境構築手順](#環境構築手順)を完了し、`sail up -d`でアプリケーションを起動してください。Google設定を行わなくても、LMS内の空き枠表示・予約・キャンセルは従来どおり動作します。

#### 1. Google Cloud側の準備

Google Cloud Console（<https://console.cloud.google.com/>）へGoogleアカウントでログインします。画面名は変更される可能性があるため、必要に応じて公式ガイドも参照してください。

- [Google Calendar APIを有効にする公式手順](https://developers.google.com/workspace/calendar/api/quickstart)
- [OAuth 2.0 Web Serverアプリケーション公式ガイド](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Google Calendar APIのOAuthスコープ](https://developers.google.com/workspace/calendar/api/auth)

1. 画面上部のプロジェクト選択から既存プロジェクトを選ぶか、**新しいプロジェクト**を作成します。
2. 左上のメニューから **APIとサービス → ライブラリ** を開きます。
3. `Google Calendar API`を検索し、対象プロジェクトで **有効にする** を押します。
4. **APIとサービス → OAuth同意画面**を開き、アプリ名、サポートメール、デベロッパー連絡先を入力します。外部ユーザーとして作成した場合は、**テストユーザー**に実際に動作確認するGoogleアカウントのメールアドレスを追加します。
5. OAuth同意画面のスコープ設定では、次の2つを追加します。カレンダー選択UIはなく、実装はプライマリカレンダー（`primary`）だけを使用します。
   - `https://www.googleapis.com/auth/calendar.freebusy`
   - `https://www.googleapis.com/auth/calendar.events.owned`
6. **APIとサービス → 認証情報 → 認証情報を作成 → OAuthクライアントID**を開き、アプリケーションの種類で **ウェブアプリケーション**を選びます。
7. **承認済みのリダイレクトURI**に、次のURLを完全一致で追加します。`APP_URL`を変更する場合は、ホスト・ポート・パスを実際のURLに置き換えてください。

   ```text
   http://localhost:8000/settings/google-calendar/callback
   ```

8. **作成**を押し、表示されたクライアントIDとクライアントシークレットを安全な場所へ一時的に控えます。既存のクライアントを使う場合は、認証情報一覧から対象クライアントを開いて確認します。

#### 2. `.env`の設定

リポジトリルートの`.env`へ、`config/services.php`が参照する次の変数を設定します。値はREADMEやGitへ記載しないでください。

```dotenv
GOOGLE_CALENDAR_CLIENT_ID=発行されたクライアントID
GOOGLE_CALENDAR_CLIENT_SECRET=発行されたクライアントシークレット
GOOGLE_CALENDAR_REDIRECT_URI=http://localhost:8000/settings/google-calendar/callback
```

`.env.example`には空値とローカル用リダイレクトURIを記載済みです。設定変更後、実行中のSailコンテナへ反映するため、次を実行します。

```bash
sail config:clear
sail up -d --force-recreate
sail artisan optimize:clear
```

`config:clear`が利用できない場合は、`./vendor/bin/sail`に読み替えてください。設定値の確認は秘密値を表示しない形で行います。

```bash
sail artisan tinker --execute="dump(config('services.google_calendar.client_id') !== null, config('services.google_calendar.redirect_uri'));"
```

期待結果は、1つ目が`true`、2つ目が設定したリダイレクトURIです。クライアントシークレットやトークンは表示しないでください。

#### 3. デモCredentialと実OAuth連携の違い

`migrate:fresh --seed`または`db:seed`をローカル・テスト環境で実行すると、`coach@certify-lms.test`にデモCredentialが1件作成されます。これはGoogle APIへ送信できないダミー値です。

- `coach@certify-lms.test`：デモ上は連携済み。ただし実Google APIには接続できません。
- `coach2@certify-lms.test`：未連携。Google APIを呼ばず、従来のLMS判定を確認できます。
- `GoogleCalendarSeeder`は`local`・`testing`環境だけで実行され、既存Credentialを`firstOrCreate`で上書きしません。
- 実Google確認には、後述の手順でコーチ本人がOAuth連携してください。

#### 4. 実Googleアカウントで連携する

1. `http://localhost:8000`を開きます。
2. `coach@certify-lms.test`でログインします。初期パスワードは`password`です。
3. 設定画面の **面談設定** タブを開きます。
4. **Google Calendarを連携**（表示文言が異なる場合はGoogle連携の開始ボタン）を押します。
5. Googleの同意画面で、テストユーザーに登録したアカウントを選び、上記2スコープを許可します。
6. GoogleからLMSへ戻り、面談設定タブに連携済みの状態が表示されることを確認します。
7. 連携に失敗した場合は、後述のエラー確認を行い、`.env`のリダイレクトURIとGoogle Cloudの登録値が完全一致しているか確認します。

#### 5. Step 6の手動確認

実Googleアカウントで、次の順番で確認します。Google Calendar側の予定はテスト用の時間帯に作成してください。

1. **空き枠**：Google Calendarで予定を作成し、LMSの受講生アカウント（`student@certify-lms.test` / `password`）で面談予約画面を開きます。予定と重なるコーチの枠が人数から除外され、未連携コーチの枠は従来どおり表示されることを確認します。
2. **予約**：空いている時間帯を予約します。コーチのGoogle Calendarに、タイトルが`面談`、開始・終了が60分、説明欄が固定面談URLだけのイベントとして作成されることを確認します。生徒名、資格名、相談内容、Google Meet URLが含まれていないことも確認します。
3. **キャンセル**：LMSで予約をキャンセルします。連携中は対応するGoogleイベントが削除され、LMSの面談回数が返却されることを確認します。
4. **解除**：面談設定タブからGoogle連携を解除します。解除時に既存Googleイベントが削除されないことを確認します。
5. **解除後キャンセル**：解除後にLMS面談をキャンセルします。LMSのキャンセル・回数返却は成立し、Google Calendarへ削除操作が行われないことを確認します。
6. **未連携コーチ**：`coach2@certify-lms.test`で連携せず、空き枠と予約を確認します。Google APIを使わず、従来のLMS機能が動くことを確認します。

Google API障害時は、LMSの空き枠・予約・キャンセルが継続することが仕様です。API失敗はサーバーログへ記録されますが、トークンやGoogleのレスポンス本文はログへ出力しません。

#### Googleイベント作成後の応答喪失について

Google Calendar側でイベント作成が成功していても、通信断・タイムアウトなどにより、LMSがGoogleからイベントIDを取得できない場合があります。

この場合、LMS側ではGoogleイベントを特定できないため、後からLMSで面談をキャンセルしても、Google Calendar側に予定が残る可能性があります。

予定が残っている場合は、コーチがGoogle Calendar上で対象の「面談」予定を確認し、必要に応じて手動で削除してください。

このケースについて、S-A-01では追加の同期・復旧処理、自動リトライ、キュー、後追い同期は行いません。PM承認済みの既知の残存リスクとして扱います。

#### 6. よくあるエラー

| 症状 | 確認箇所 |
|---|---|
| `redirect_uri_mismatch` | `.env`の`GOOGLE_CALENDAR_REDIRECT_URI`とGoogle Cloudの承認済みURIが完全一致しているか確認し、`sail artisan optimize:clear`後にコンテナを再起動する |
| `access_denied` | OAuth同意画面のテストユーザーに、ログイン中のGoogleアカウントを追加する |
| OAuthクライアントが見つからない | `.env`のClient ID・Secretが同じGoogle Cloudプロジェクトのものか確認する。値をログへ出さない |
| 連携開始時に設定エラー | `.env`の3変数、`config/services.php`の参照名、Sailコンテナの再作成を確認する |
| 空き枠が減らない | Google予定の時間帯、連携コーチ、Calendar API有効化、FreeBusy scope、アプリのログを確認する |
| 予約イベントが作成されない | アクセス期限、refresh token、`calendar.events.owned` scope、Google側の権限を確認する。LMS予約自体は継続する |
| キャンセルでGoogle予定が消えない | 連携解除済み・再連携済みでは仕様上Google操作を行わない。連携中の場合はイベントIDとログを確認する |

実Googleのトークン失効や権限変更を再現する場合は、テスト用Googleアカウントを使用してください。アクセストークン、リフレッシュトークン、クライアントシークレットをIssue、README、コミット、画面共有、ログへ貼り付けないでください。

#### Gemini・Stripeについて

Stripe Checkout（S-A-03）のローカル確認では、`.env`へテストモードの秘密値を設定します。秘密値はGit管理対象へ追加しません。

```dotenv
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

Stripe CLIでWebhookをローカルへ転送します。

```bash
stripe login
stripe listen --forward-to localhost:8000/webhooks/stripe
```

`stripe listen`が表示する`whsec_...`を`STRIPE_WEBHOOK_SECRET`へ設定します。本番Dashboardのsecretと混同せず、設定変更後はSailを再起動してください。

1. 受講中のstudentで`/meeting-quota/checkout`を開き、StripeテストモードのカードでCheckoutを完了する。
2. `payments`、`meeting_quota_transactions`の`purchased`行、残面談回数を確認する。
3. Stripe CLIの転送ログとアプリケーションログで署名エラー・照合不一致・一時障害を確認する。
4. 同じイベントを再送し、購入取引が増えないことを確認する。

一時障害ではWebhookがHTTP 500を返して再送対象になります。未払い・恒久的不一致はHTTP 200で受信し、加算しません。秘密鍵、Webhook secret、カード情報はREADMEやログへ記載しません。

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
