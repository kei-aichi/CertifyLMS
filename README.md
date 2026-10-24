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

以降のコマンドは、cloneしたリポジトリのルートディレクトリ（`artisan`ファイルがあるディレクトリ）で実行してください。

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

このaliasは現在開いているターミナルのセッションに対して有効です。新しいターミナルを開いた場合は、再度設定してください。

### 5. コンテナの起動

```bash
sail up -d
```

初回起動時はDockerイメージの取得やLaravel Sailアプリケーションのビルドが行われるため、通常より時間がかかる場合があります。

### 6. アプリケーションの初期化

> `migrate:fresh --seed`は既存の開発DBを削除してから、MigrationとSeederを実行します。既存データを保持する場合は実行せず、`sail artisan migrate`を使用してください。

```bash
sail artisan key:generate
sail artisan storage:link
sail artisan migrate:fresh --seed
```

`storage:link` は教材画像・プロフィール画像の配信に必要です。`migrate:fresh --seed` でテーブル作成とデモデータ投入が行われます（いつでも再実行してデータを初期状態に戻せます）。

### 通常起動

初回環境構築が完了した後、Webアプリケーションを起動する場合は次を実行します。

```bash
sail up -d
```

通常起動では `migrate:fresh --seed` を実行しません。既存データを保持したままMigrationだけを適用する場合は、次を実行します。

```bash
sail artisan migrate
```

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

以下のコマンドは、特に記載がない限りプロジェクトルートのホスト側ターミナルで実行します。Google Cloud Consoleとブラウザ操作は同じ環境で行います。

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
sail up -d --force-recreate
sail artisan optimize:clear
```

Google CalendarのClient ID、Client Secret、Redirect URIなど、コンテナへ渡すGoogle Calendar関連の環境変数を変更した場合は、実行中のコンテナへ反映するため `--force-recreate` を使用します。その他の`.env`変更についても、変更した値がコンテナの環境変数として読み込まれる構成の場合は、同じ手順が必要です。`.env`を変更していない通常の起動では、`sail up -d` で構いません。

設定値の確認は秘密値を表示しない形で行います。

```bash
sail artisan tinker --execute="dump(config('services.google_calendar.client_id') !== null, config('services.google_calendar.redirect_uri'));"
```

期待結果は、1つ目が`true`、2つ目が設定したリダイレクトURIです。クライアントシークレットやトークンは表示しないでください。

#### 3. デモCredentialと実OAuth連携の違い

`migrate:fresh --seed`または`db:seed`をローカル・テスト環境で実行すると、`coach@certify-lms.test`に画面上の「連携済み」状態を再現するダミーCredentialが1件作成されます。このCredentialは実際のGoogle OAuthトークンではなく、Google APIへ接続できません。実Google Calendarとの連携確認には、必ずコーチ本人のアカウントでOAuth連携を実行してください。

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
| `redirect_uri_mismatch` | `.env`の`GOOGLE_CALENDAR_REDIRECT_URI`とGoogle Cloudの承認済みURIが完全一致しているか確認し、`.env`を変更した場合は `sail up -d --force-recreate` の後に `sail artisan optimize:clear` を実行する |
| `access_denied` | OAuth同意画面のテストユーザーに、ログイン中のGoogleアカウントを追加する |
| OAuthクライアントが見つからない | `.env`のClient ID・Secretが同じGoogle Cloudプロジェクトのものか確認する。値をログへ出さない |
| 連携開始時に設定エラー | `.env`の3変数、`config/services.php`の参照名、Sailコンテナの再作成を確認する |
| 空き枠が減らない | Google予定の時間帯、連携コーチ、Calendar API有効化、FreeBusy scope、アプリのログを確認する |
| 予約イベントが作成されない | アクセス期限、refresh token、`calendar.events.owned` scope、Google側の権限を確認する。LMS予約自体は継続する |
| キャンセルでGoogle予定が消えない | 連携解除済み・再連携済みでは仕様上Google操作を行わない。連携中の場合はイベントIDとログを確認する |

実Googleのトークン失効や権限変更を再現する場合は、テスト用Googleアカウントを使用してください。アクセストークン、リフレッシュトークン、クライアントシークレットをIssue、README、コミット、画面共有、ログへ貼り付けないでください。

### Gemini AIチャット（S-A-02）

Gemini APIを利用した学習相談機能です。学習中（`in_progress`）の`student`のみ利用できます。`coach`、`admin`、卒業・退会・招待中の受講生は利用できません。

以下のコマンドは、特に記載がない限りプロジェクトルートのホスト側ターミナルで実行します。Gemini APIキーの作成はGoogle AI Studioのブラウザで行い、動作確認はLMSのブラウザ画面で行います。

- フル画面チャット：`http://localhost:8000/ai-chat`
- Widget：学習画面の右下に表示（AIチャット機能が有効な学習中studentのみ）
- Section画面から開始した相談は、Sectionと資格のコンテキストをAIへ渡します
- 同じSectionの会話は再利用されます
- Widgetの「新しい相談」を選ぶと、新しいConversationを作成します
- 送信上限は初期値20回/日です

#### 1. Gemini APIキーの取得

1. [Google AI Studio](https://aistudio.google.com/)へGoogleアカウントでログインします。
2. APIキー管理画面から開発用APIキーを作成します。画面名や導線はGoogle側の変更により異なる場合があります。
3. APIキーの利用制限・利用可能モデル・請求設定は、利用するGoogleプロジェクトの設定に従ってください。

APIキーの実値は、README、Issue、Git、画面共有、ログへ記載しないでください。

#### 2. `.env`の設定

共通の[環境構築手順](#環境構築手順)で`.env`を作成した後、次を設定します。

```dotenv
AI_CHAT_ENABLED=true
AI_CHAT_DAILY_LIMIT=20
AI_CHAT_AUTO_TITLE_ENABLED=true
AI_CHAT_PENDING_TIMEOUT_SECONDS=120
GEMINI_API_KEY=ここにAPIキーを設定
GEMINI_MODEL=gemini-3.1-flash-lite
GEMINI_CONNECT_TIMEOUT=10
GEMINI_TIMEOUT=30
GEMINI_MAX_RETRIES=2
GEMINI_TITLE_TIMEOUT=10
GEMINI_TITLE_MAX_RETRIES=0
```

| 変数 | 用途 | 初期値 |
|---|---|---:|
| `AI_CHAT_ENABLED` | AIチャット機能のON/OFF | `false` |
| `AI_CHAT_DAILY_LIMIT` | 受講生1人あたりの日次送信上限 | `20` |
| `AI_CHAT_AUTO_TITLE_ENABLED` | 初回成功時の自動タイトル生成 | `true` |
| `AI_CHAT_PENDING_TIMEOUT_SECONDS` | stale pendingと判定する経過秒数 | `120` |
| `GEMINI_API_KEY` | Gemini API認証キー | なし |
| `GEMINI_MODEL` | 使用するGeminiモデル | `gemini-3.1-flash-lite` |
| `GEMINI_CONNECT_TIMEOUT` | API接続タイムアウト（秒） | `10` |
| `GEMINI_TIMEOUT` | APIリクエストタイムアウト（秒） | `30` |
| `GEMINI_MAX_RETRIES` | 一時エラー時の最大再試行回数 | `2` |
| `GEMINI_TITLE_TIMEOUT` | 自動タイトル生成のタイムアウト（秒） | `10` |
| `GEMINI_TITLE_MAX_RETRIES` | 自動タイトル生成の最大再試行回数 | `0` |

`GEMINI_MODEL`は環境変数で変更できます。利用可能なモデルはAPIキーとGoogle側の提供状況に依存します。現在の既定値は`gemini-3.1-flash-lite`です。

#### 3. 設定の反映

`.env`を変更した場合は、プロジェクトルートで次を実行します。

```bash
./vendor/bin/sail artisan config:clear
```

設定キャッシュを利用する環境では、必要に応じて次も実行します。

```bash
./vendor/bin/sail artisan config:cache
```

Sailが起動していない場合は、先に共通手順どおり起動します。

```bash
./vendor/bin/sail up -d
```

#### 4. DBと初期データ

新規、または破棄してよい開発DBでは、共通の初期化手順を実行します。

`migrate:fresh --seed`は既存のテーブルとデータを削除してから、migrationとSeederを実行します。既存データを保持する環境では実行しないでください。

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

実行後はログインアカウントと対象Sectionが作成されていることを確認します。既存データを残す場合は、このコマンドを実行せず、必要なmigrationまたは`AiChatSeeder`だけを実行してください。

既存DBでは、データを保持するため通常のMigrationを実行します。

```bash
./vendor/bin/sail artisan migrate
```

AIチャット確認用データだけを追加する場合は、固定studentと対象Sectionが存在することを確認したうえで、次を実行します。

```bash
./vendor/bin/sail artisan db:seed --class='Database\\Seeders\\AiChatSeeder'
```

`AiChatSeeder`は`local`または`testing`環境以外では実行されません。固定student（`student@certify-lms.test`）または対象Sectionが存在しない場合は警告して終了します。既存Conversationを無制限に増やさず、Gemini APIやDailyUsageを使用しません。

`DatabaseSeeder`では、User・Enrollment・Content投入後に`AiChatSeeder`が呼び出されます。

Gemini APIへ接続しないモックテストは、後述の「外部API連携テスト」を参照してください。

#### 5. ログインと利用確認

共通の[ログインアカウント](#ログインアカウント)にある受講生でログインします。

```text
メールアドレス：student@certify-lms.test
パスワード：password
```

正常系の確認手順：

1. `http://localhost:8000`へアクセスしてログインする。
2. `http://localhost:8000/ai-chat`を開く。
3. Conversationを作成し、短い質問を送信する。
4. 日本語のassistant回答が表示されることを確認する。
5. ページを再読み込みし、質問と回答が保存・再表示されることを確認する。
6. Section画面を開き、右下のWidgetから質問を送信する。
7. Widgetから全画面へ遷移し、Section情報が維持されることを確認する。
8. 同じSectionで再度相談し、既存Conversationが再利用されることを確認する。
9. Widgetの「新しい相談」を選び、同じSectionでも新しいConversationが作成されることを確認する。
10. 初回成功後にConversationタイトルが自動生成されることを確認する。

#### 6. 日次制限・エラー時の挙動

- 日次送信回数は`ai_chat_daily_usages`で管理し、初期上限は20回です。
- JSTの日付単位で集計され、Conversationを削除しても利用回数は減りません。
- Gemini API失敗時も質問とassistant errorは履歴へ保存され、利用回数は戻りません。
- APIキー未設定時は503相当の利用不可案内となり、送信予約は保存されません。
- 日次上限超過時は429相当の案内となり、新しい送信は保存されません。

#### 7. よくある問題

| 症状 | 確認内容 |
|---|---|
| `/ai-chat`が404になる | `AI_CHAT_ENABLED=true`、設定キャッシュ、ログイン中ユーザーがstudentで`in_progress`かを確認する |
| APIキー未設定の案内が表示される | `.env`の`GEMINI_API_KEY`を確認し、`./vendor/bin/sail artisan config:clear`を実行する |
| Gemini APIが404になる | `GEMINI_MODEL`がAPIキーで利用可能か、Google AI Studio側のモデル提供状況を確認する |
| Gemini APIが429になる | Google側のAPI利用制限と、アプリ側の`AI_CHAT_DAILY_LIMIT`を確認する |
| 回答は保存されたがタイトルが変わらない | afterResponseの実行環境とPHP-FPM/Webサーバー/プロキシのtimeoutを確認する |
| Widgetが表示されない | `AI_CHAT_ENABLED=true`、studentロール、`in_progress`状態、AIチャット画面以外であることを確認する |

AIタイトル生成はHTTPレスポンス送信後のLaravel `afterResponse`で実行されます。Queue Workerではないため、実行環境のtimeoutやプロセス終了によりタイトル生成が完了しない場合があります。その場合も回答済みのConversationとMessageは保持されます。

#### APIキーの安全管理

- APIキーをREADME、Issue、Git、画面共有、チャット、ログへ貼り付けない。
- `.env`はGitへコミットしない。
- APIキーをコマンド引数やデバッグ出力へ直接記載しない。
- 漏えいした可能性がある場合は、Google側でキーを無効化・再発行する。

### Stripe決済（S-A-03）

Stripe CheckoutとWebhookを利用した面談回数購入機能です。

Stripe Checkoutの実通信確認はStripeのテストモードを使用します。外部APIへ接続しないモックテストの実行方法は、前述の「外部API連携テスト」を参照してください。

Stripe Checkout（S-A-03）のローカル確認では、`.env`へテストモードの秘密値を設定します。秘密値はGit管理対象へ追加しません。

以下の設定・コマンドは、特に記載がない限りプロジェクトルートのホスト側ターミナルで実行します。Stripe CLIはホスト側のターミナルで起動し、LaravelのコマンドはSail経由で実行します。

1. Stripe Dashboardのサンドボックス環境で、Developers → API keysからテスト用Secret key（`sk_test_...`）を取得し、`.env`の`STRIPE_SECRET`へ設定します。本番Dashboardのsecretや`sk_live_...`は使用しません。

```dotenv
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=取得したWebhook Secret
```

2. Stripe CLIは、使用するOSに合った[公式インストール手順](https://docs.stripe.com/stripe-cli)を参照してホスト側へインストールします。

3. ホスト側ターミナルで次を実行し、ブラウザでStripeへの認証を完了します。

```bash
stripe login
```

ブラウザが開かない場合の認証方法は、使用しているOSとStripe CLIの公式手順に従ってください。認証完了はCLIにログイン成功の表示が出ることで確認します。

4. Checkoutを開始する前に、別のホスト側ターミナルでWebhook転送を開始します。

```bash
stripe listen \
  --events checkout.session.completed,checkout.session.expired \
  --forward-to http://localhost:8000/webhooks/stripe
```

5. `stripe listen`の実行結果に表示される`whsec_...`を`.env`の`STRIPE_WEBHOOK_SECRET`へ設定します。

6. プロジェクトルートのホスト側ターミナルで、Laravelへ設定を反映します。

```bash
./vendor/bin/sail artisan config:clear
```

`config:clear`が完了したことを確認してからCheckoutを開始します。`stripe listen`は、Checkout・Webhook受信・DB確認・再送確認が終わるまで、別ターミナルで起動したままにしてください。

7. 受講中のstudentで`/meeting-quota/checkout`を開き、[Stripe公式テストカード一覧](https://docs.stripe.com/testing#cards)のカード番号でCheckoutを完了します。
8. Stripe CLIにHTTP 200の転送結果が表示され、決済成功画面が表示されることを確認します。`payments`の`succeeded`、`meeting_quota_transactions`の`purchased`行、残面談回数も確認します。
9. Webhookイベントを再送し、Payment状態・購入取引件数・残面談回数が増えないことを確認します（詳細は「WebhookイベントIDの確認と再送」を参照）。Stripe CLIの転送ログとアプリケーションログで署名エラー・照合不一致・一時障害も確認します。

#### WebhookイベントIDの確認と再送

Webhookの再送確認では、決済に対応するイベントID（`evt_...`）を特定してから操作します。`stripe listen --forward-to`は、Stripeから届いたWebhookをローカルの`http://localhost:8000/webhooks/stripe`へ転送するための機能です。Dashboardに登録したWebhook Endpointへの再送とは別の経路であり、Dashboardから再送したイベントが必ずローカルのStripe CLIへ届くわけではありません。

Dashboardで再送する場合は、Dashboardに登録済みのWebhook Endpointを対象に、サンドボックス環境で次を確認します。

1. **Developers → Webhooks**を開く。
2. 登録済みの対象Webhook Endpointを選び、その配信履歴から`checkout.session.completed`のイベントを選ぶ。
3. イベント詳細に表示される`evt_...`の値を控える。Checkout Session ID（`cs_...`）やPaymentIntent ID（`pi_...`）とは別の値です。
4. イベント詳細の**Resend / 再送**操作を選び、Dashboardに登録済みの対象Endpointへ再送する。
5. 対象Endpointの配信結果がHTTP 200になることを確認する。

Dashboardの表示名や配置はStripeの画面変更により異なる場合があります。再送操作が表示されない場合は、対象イベントの詳細画面からWebhook配信先と配信履歴を確認してください。現在のリポジトリでは、ローカルの`stripe listen`へイベントを再送するCLIコマンドは確認していないため、ローカル再送を実施できるとは記載しません。

再送後は、次の3点を確認します。

- `payments`で対象Paymentが`succeeded`のままであること。
- `meeting_quota_transactions`で対象Paymentの`type=purchased`行が1件だけであること。再送前後で件数が増えないこと。
- ダッシュボードまたは予約画面を再読み込みし、残面談回数が購入数量分だけで、再送によってさらに増えていないこと。

Paymentの確認は、開発環境のphpMyAdmin（<http://localhost:8080>）で`payments`と`meeting_quota_transactions`を検索するか、アプリケーションログの秘密情報を含まない照合結果で行います。Webhook payload、署名secret、APIキー、カード情報はログやREADMEへ貼り付けません。

#### `migrate` / `migrate:fresh --seed`後のStripe関連データ

初期化が必要な開発環境では、既存の開発データが削除されることを確認したうえで、プロジェクトルートから次を実行します。

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

`PaymentSeeder`が実行されるため、Stripeへ実通信を行わないダミーのPaymentが投入されます。初期化後はphpMyAdminまたは次の確認用コマンドで、`payments`に`pending`・`succeeded`・`failed`の状態があり、`succeeded`のPaymentにだけ`purchased`取引があることを確認します。

```bash
./vendor/bin/sail artisan tinker --execute="echo App\\Models\\Payment::query()->select('status')->selectRaw('count(*) as count')->groupBy('status')->get();"
```

`succeeded`のPaymentに紐づく購入取引は、次の読み取り専用コマンドで確認します。`related_payment_id`がPayment IDと一致し、`type`が`purchased`の行が対象Paymentごとに1件で、`amount`がPaymentの`quantity`と一致することを確認します。

```bash
./vendor/bin/sail artisan tinker --execute="echo DB::table('payments')->join('meeting_quota_transactions', 'meeting_quota_transactions.related_payment_id', '=', 'payments.id')->where('payments.status', 'succeeded')->where('meeting_quota_transactions.type', 'purchased')->select('payments.id', 'payments.quantity', 'meeting_quota_transactions.related_payment_id', 'meeting_quota_transactions.amount')->selectRaw('count(meeting_quota_transactions.id) as purchased_transaction_count')->groupBy('payments.id', 'payments.quantity', 'meeting_quota_transactions.related_payment_id', 'meeting_quota_transactions.amount')->get();"
```

SeederのStripe IDはローカル確認用のダミー値です。Stripe APIへの照会やWebhook再送の対象にはしません。通常のマイグレーションだけを適用する場合は、既存データを保持する次のコマンドを使用します。

```bash
./vendor/bin/sail artisan migrate
```

`migrate:fresh --seed`は開発環境のデータを初期化するため、共有環境や本番環境では実行しないでください。

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

## Queue worker（Database Queue）

以下はリポジトリルートで実行してください。通知・メールの非同期処理を実行するには、Database Queue workerを起動します。

### 起動前の設定確認

`.env.example`では、次のローカル設定を使用します。

- Queue接続：`QUEUE_CONNECTION=database`
- Database Queue：`after_commit=true`
- Queueの再取得待ち時間：`retry_after=180`
- SMTP：`MAIL_HOST=mailpit`、`MAIL_PORT=1025`

実行中のSailコンテナが読み込んでいる設定は、次で確認できます。秘密情報は表示しません。

```bash
sail artisan tinker --execute="echo 'queue='.config('queue.default').PHP_EOL; echo 'after_commit='.(config('queue.connections.database.after_commit') ? 'true' : 'false').PHP_EOL; echo 'smtp='.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').PHP_EOL;"
```

`queue=database`、`after_commit=true`、`smtp=mailpit:1025`が表示されることを確認してください。

### Workerの起動

別のターミナルで次を実行してください。

```bash
sail artisan queue:work database --queue=default --timeout=120
```

`retry_after`は180秒、workerの`--timeout`は120秒です。workerを起動していない場合、投入されたJobは`jobs`テーブルに残り、通知は処理されません。

停止は`Ctrl+C`で行います。失敗Jobの確認と再投入は次のコマンドで行います。

```bash
sail artisan queue:failed
```

`queue:failed`は失敗したJobの一覧確認だけを行います。worker起動後は、対象Jobが処理されて`jobs`から消えること、アプリケーション画面の通知、Mailpit（<http://localhost:8025>）のメールを確認してください。既存データを変更したくない場合は、検証用データを用意してから確認してください。

`queue:retry all`は全failed Jobを再投入するため、通常の確認手順では実行しません。特に面談リマインダーの復旧には使用しないでください。

## Scheduler

Schedulerは、期限切れEnrollment処理、招待期限切れ処理、学習セッションの自動クローズ、面談の自動完了、面談リマインダーなど、時刻に基づく定期処理を実行します。対象スケジュールは`app/Console/Kernel.php`に定義されています。

WebアプリケーションおよびQueue Workerとは別のターミナルで、次を実行してください。

```bash
sail artisan schedule:work
```

Schedulerは定期実行Commandを起動するプロセスです。通知・メールの非同期Jobを処理するには、Queue Workerも別途起動してください。停止する場合は`Ctrl+C`を押してください。

### 開発中に起動するプロセス

| プロセス | コマンド | 役割 |
|---|---|---|
| Webアプリケーション | `sail up -d` | Laravel、MySQL、Mailpit等を起動 |
| Queue Worker | `sail artisan queue:work database --queue=default --timeout=120` | Queueに投入された通知・メール等のJobを処理 |
| Scheduler | `sail artisan schedule:work` | 定期実行Commandを起動 |

Queue WorkerとSchedulerは、Webアプリケーションのコンテナ起動とは別に、必要な機能を確認する場合のみ起動してください。

## 面談リマインダーの失敗Job復旧

面談リマインダーの失敗Jobは、対象のdispatch・チャネル・`failed_jobs.uuid`を指定して専用Commandで復旧します。

```bash
sail artisan meeting-reminders:retry <dispatch_id> <database|mail> <failed_jobs.uuid>
```

上の`<...>`を含むコマンドは、そのまま実行できません。山括弧内を実際の値に置き換えてください。

- `dispatch_id`：対象の面談リマインダー配信台帳のID。対象Jobのpayload metadataと`meeting_reminder_dispatches`を照合して取得します。
- `database|mail`：復旧するチャネル。Database通知なら`database`、メールなら`mail`を指定します。
- `failed_jobs.uuid`：`sail artisan queue:failed`で確認した対象failed Jobの`uuid`列の値です。

例（値は検証対象に置き換えてください）：

```bash
sail artisan meeting-reminders:retry 01dispatch... mail 550e8400-e29b-41d4-a716-446655440000
```

実行前に、`queue:failed`で対象UUIDを確認し、指定したJobのpayload metadataと次の値が一致することを確認してください。

- dispatch ID
- channel（`database`または`mail`）
- dispatch token
- Queue attempt UUID

標準の`queue:retry all`は、対象外のfailed Jobまで再投入し、チャネル・dispatch・tokenの照合やMeetingの状態確認を行いません。面談リマインダーは必ず`meeting-reminders:retry`で対象を限定します。

### `unknown`状態の扱い

Queue投入処理中にプロセス停止や例外が発生すると、Queue投入成否を確定できないQueue attemptが`unknown`になることがあります。これは、QueueにJobが存在する場合と存在しない場合を安全に区別できない状態です。

`unknown`は自動再投入および通常の`meeting-reminders:retry`の対象外です。管理者は次の情報を確認し、送信状況を確定できない場合は再投入せず、PMまたは運用責任者へエスカレーションしてください。

- dispatch IDとchannel
- dispatch token
- Queue attempt UUIDとstate
- `jobs`テーブルの対象Queue Job
- `failed_jobs`テーブルの対象failed Job
- Meetingの状態と開始時刻
- recipient Userの存在・status・role

Mailチャネルは、外部送信成功後に状態更新前の停止が起こると送信結果を確定できず、再投入によって重複メールになる可能性があります。確認なしで再投入しないでください。

Meetingが開始済み、キャンセル済み、またはrecipient Userが無効の場合は、失敗Jobであっても送信しません。

testing DBで検証する場合は、開発DBへ接続していないことを確認し、Mailpit以外のSMTPへ送信しないでください。検証用workerは対象Queueだけを処理し、既存の`failed_jobs`を全件retry・削除しないでください。
## 外部API連携テスト

Google OAuth・Google Calendar、Gemini、Stripeの外部API連携テストは、実APIへ接続せずモックで実行します。

外部API連携のモックテストは`external-api` Groupで実行できます。

```bash
sail artisan test --group external-api
```

未モック通信が拒否されることを確認する通信ガード専用テストは、`external-api-guard` Groupで実行できます。

```bash
sail artisan test --group external-api-guard
```

全テストは次のコマンドで実行できます。

```bash
sail artisan test
```

外部API連携テストを除外して実行する場合は、次を使用します。

```bash
sail artisan test --exclude-group external-api
```

`external-api`と`external-api-guard`は別Groupです。`external-api`を除外しても、通信ガード専用テストは自動的には除外されません。

テスト時の通信制御は次のとおりです。

- Laravel HTTP Client: `Http::preventStrayRequests()`で未モック通信を拒否します。`Http::fake()`で明示的にモックした通信は実行できます。
- Google SDK: `GoogleClientFactory`経由で生成したClientへテスト用HTTPクライアントを注入し、実通信開始前に失敗させます。
- Stripe SDK: テスト中だけHTTPクライアントを差し替え、テスト終了時に元のクライアントへ復元します。
- Gemini: `Http::fake()`でHTTPレスポンスをモックします。

これらのモックテストでは、Google CalendarのClient ID・Client Secret、Gemini APIキー、Stripe Secret・Webhook Secretなどの実値は不要です。テストコードが設定するダミー値またはモック応答だけを使用します。

通信ガードには次の適用範囲があります。

- `Http::preventStrayRequests()`だけで、Laravel HTTP Client以外の通信をすべて遮断するものではありません。
- `GoogleClientFactory`を経由しないGoogle Clientは保証対象外です。
- Stripe SDKのstreaming通信は対象外です。
- 実APIを使う手動確認は、ここで説明するモックテストとは別に実施します。

APIキー、Secret、TokenはGit、ログ、Issue、画面共有へ記載しないでください。実APIを使う手動確認については、Google Calendar、Gemini、Stripe各節の手順を参照してください。

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
- `AI_CHAT_ENABLED` — Gemini AI チャットボットの機能スイッチです（既定値 `false`）。
- `AI_CHAT_DAILY_LIMIT` — 受講生1人あたりの1日送信上限です（既定値 `20`）。
- `AI_CHAT_AUTO_TITLE_ENABLED` — 会話タイトルの自動生成を有効にします（既定値 `true`）。
- `AI_CHAT_PENDING_TIMEOUT_SECONDS` — 送信処理中（pending）をstaleと判定して復旧対象にする経過秒数です（既定値 `120` 秒。stale pending復旧は実装済み）。
- `GEMINI_API_KEY`、`GEMINI_MODEL`、`GEMINI_CONNECT_TIMEOUT`、`GEMINI_TIMEOUT`、`GEMINI_MAX_RETRIES` — Gemini API連携の認証、モデル、タイムアウト、再試行設定です。
- `GEMINI_TITLE_TIMEOUT`、`GEMINI_TITLE_MAX_RETRIES` — afterResponseで実行する自動タイトル生成用のtimeout・再試行設定です。タイトル生成は補助処理のため、既定値は10秒・0回です。

AIチャットの自動タイトル生成はHTTPレスポンス送信後のLaravel afterResponseで実行します。afterResponseはQueue Workerではないため、PHP-FPM・Webサーバー・プロキシのtimeoutやプロセス終了によりタイトル生成が完了しない場合があります。その場合も回答済みの会話・メッセージは保持され、タイトルは既存値のままです。

新しい環境変数やセットアップ手順を追加した場合は、`.env.example` と本 README に追記し、チームの誰でも環境を再現できる状態を保ってください。
