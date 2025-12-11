<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Manual {
    public static function match($question, $set_id, $threshold, $use_reranker = false) {
        $normalized_q = Chatbot_Normalizer::normalize($question);
        $q_vec = Chatbot_Embedder::embed_text($normalized_q);

        $candidates = Chatbot_Repository::find_manual_answers($set_id, 100);
        $best = null;
        $best_score = 0.0;

        foreach ($candidates as $row) {
            $pattern_norm = Chatbot_Normalizer::normalize($row->question_pattern);
            if ($pattern_norm === $normalized_q) {
                return ['id' => $row->id, 'answer' => $row->answer_text, 'score' => 1.0];
            }
            $vec = $row->embedding_vector ? json_decode($row->embedding_vector, true) : Chatbot_Embedder::embed_text($pattern_norm);
            $score = Chatbot_Embedder::similarity($q_vec, $vec);
            if ($score > $best_score) {
                $best_score = $score;
                $best = $row;
            }
        }

        if ($best && $best_score >= $threshold) {
            if ($use_reranker) {
                $ok = self::rerank_yes_no($question, $best->answer_text);
                if (!$ok) {
                    return null;
                }
            }
            return ['id' => $best->id, 'answer' => $best->answer_text, 'score' => $best_score];
        }
        return null;
    }

    private static function rerank_yes_no($question, $answer) {
        // 簡易な判定: 長さが極端に乖離していないかを確認（実LLM判定は後続実装）
        $qlen = mb_strlen($question);
        $alen = mb_strlen($answer);
        if ($qlen > 0 && ($alen / max(1, $qlen)) > 50) {
            return false;
        }
        return true;
    }
}
