# Chatbot RAG Plugin

WordPress 用の資料ベースQAチャットボットプラグイン。ショートコードで設置でき、アップロードした資料をもとに Gemini File Search / OpenAI Responses API で回答します。

## 主な機能
- ショートコード設置（フローティング / インライン）
- 資料セット管理とファイルアップロード（pdf, txt, md）
- Gemini File Search / OpenAI Files へのアップロードとリモート検索
- 手動回答登録・再利用、チャットログ閲覧
- レート上限制御、管理画面設定（APIキー等）

## 必要要件
- WordPress 6.x 互換
- PHP 8.0 以上推奨
- 外部APIキー：Gemini / OpenAI のいずれか

## セットアップ
1. プラグインを配置し有効化（DBテーブルが作成されます）。
2. 管理画面「チャットボット設定」で Gemini / OpenAI の API キーを設定。
3. 「チャットボット資料アップロード」から資料セットを作成し、ファイルをアップロード。
4. 投稿や固定ページにショートコードを設置：
   - `[my_chatbot layout="floating" dataset="your_dataset_slug"]`
   - `[my_chatbot layout="inline" dataset="your_dataset_slug"]`

## 削除時
- プラグイン削除（アンインストール）でリモートファイル（Gemini / OpenAI）とローカルファイル、DBテーブルをクリーンアップします。
