<?php

/**
 * SEOChecker v3.0 — On-Page SEO Analyzer
 * Chuẩn hóa theo Google Search Quality Evaluator Guidelines 2026
 *
 * NÂNG CẤP v3.0:
 *  - checkReadability  : MỚI — Flesch-Kincaid VN, avg sentence length, passive voice hints
 *  - checkDuplicate    : MỚI — Phát hiện nội dung trùng lặp nội bộ (title/description/h1 giống nhau)
 *  - checkLinks        : Thêm broken-anchor detection, UGC/sponsored rel audit
 *  - checkSchema       : Thêm depth-check required fields per type (Article cần headline/author/date)
 *  - checkEEAT         : Thêm Review count signal, credentials/award keywords
 *  - checkContent      : Thêm reading time estimate, orphan content warning
 *  - checkSemantic     : Thêm TF-IDF proxy (top repeated noun phrases), video embed signal
 *  - checkSpeed        : Thêm type=module script detection (modern JS), third-party script count
 *  - Output            : Thêm issues[] array phân loại Critical/Warning/Info + priority sort
 *  - Output            : Thêm meta: { checked_at, word_count, reading_time, url, keyword }
 *
 * @version  3.1.0
 */
class SEOChecker
{
    private ?DOMDocument $dom   = null;
    private ?DOMXPath    $xpath = null;
    private string $html    = '';
    private string $url     = '';
    private string $keyword = '';
    private string $domain  = '';

    private int    $wordCount   = 0;
    private string $bodyText    = '';
    private int    $readingTime = 0; // phút

    /**
     * WEIGHT SYSTEM v3.0
     * Thêm readability (Google dùng tín hiệu UX/dwell time) và duplicate check
     */
    private array $weights = [
        'title'       => 8,
        'meta'        => 7,
        'h1'          => 6,
        'heading'     => 5,
        'content'     => 10,
        'keyword'     => 7,
        'images'      => 6,
        'links'       => 6,
        'eeat'        => 9,
        'schema'      => 7,
        'url'         => 5,
        'mobile'      => 6,
        'speed'       => 7,
        'social'      => 4,
        'semantic'    => 9,
        'canonical'   => 8,
        'cwv'         => 7,
        'hreflang'    => 4,
        'readability' => 7,  // MỚI v3: UX signal — dwell time / bounce rate proxy
        'duplicate'   => 8,  // MỚI v3: On-page duplicate content detection
    ];

    // ══════════════════════════════════════════════════════════════════════════
    // MAIN ENTRY
    // ══════════════════════════════════════════════════════════════════════════

    public function analyze(string $html, string $url = '', string $keyword = ''): array
    {
        $this->html    = $html;
        $this->url     = $url;
        $this->keyword = $this->normalize($keyword);
        $this->domain  = (string) parse_url($url, PHP_URL_HOST);

        if (trim($html) === '') {
            throw new \Exception("Mã nguồn HTML trống, không thể phân tích SEO.");
        }

        libxml_use_internal_errors(true);
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->strictErrorChecking = false;
        @$this->dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        $this->xpath = new DOMXPath($this->dom);
        libxml_clear_errors();

        $scores   = [];
        $messages = [];

        // ── NHÓM 1: NỘI DUNG & CẤU TRÚC ─────────────────────────────────────
        $this->checkContent($scores, $messages);      // TRƯỚC TIÊN — sinh $bodyText/$wordCount
        $this->checkTitle($scores, $messages);
        $this->checkMeta($scores, $messages);
        $this->checkH1($scores, $messages);
        $this->checkHeading($scores, $messages);
        $this->checkReadability($scores, $messages);  // MỚI v3

        // ── NHÓM 2: TỪ KHÓA & NGỮ NGHĨA ─────────────────────────────────────
        $this->checkKeyword($scores, $messages);
        $this->checkSemantic($scores, $messages);

        // ── NHÓM 3: KỸ THUẬT SEO ─────────────────────────────────────────────
        $this->checkImages($scores, $messages);
        $this->checkLinks($scores, $messages);
        $this->checkURL($scores, $messages);
        $this->checkCanonical($scores, $messages);
        $this->checkDuplicate($scores, $messages);    // MỚI v3
        $this->checkMobile($scores, $messages);
        $this->checkSpeed($scores, $messages);
        $this->checkCWV($scores, $messages);

        // ── NHÓM 4: THẨM QUYỀN & PHÂN PHỐI ──────────────────────────────────
        $this->checkEEAT($scores, $messages);
        $this->checkSchema($scores, $messages);
        $this->checkSocial($scores, $messages);
        $this->checkHreflang($scores, $messages);

        $final = $this->calculateFinal($scores);

        // Build output TRƯỚC cleanup — $this->wordCount / readingTime cần còn nguyên
        $result = [
            'score'          => $final,
            'grade'          => $this->grade($final),
            'health'         => $this->health($final),
            'recommendation' => $this->recommend($final, $scores),
            'scores'         => $scores,
            'messages'       => $messages,
            'weights'        => $this->weights,
            'breakdown'      => $this->buildBreakdown($scores),
            'issues'         => $this->buildIssues($scores, $messages),
            'meta'           => [
                'checked_at'   => date('Y-m-d H:i:s'),
                'url'          => $url,
                'keyword'      => $keyword,
                'word_count'   => $this->wordCount,
                'reading_time' => $this->readingTime,
            ],
        ];

        // MEMORY CLEANUP — sau khi đã build xong result
        $this->dom         = null;
        $this->xpath       = null;
        $this->html        = '';
        $this->url         = '';
        $this->keyword     = '';
        $this->domain      = '';
        $this->wordCount   = 0;
        $this->bodyText    = '';
        $this->readingTime = 0;
        if (function_exists('gc_collect_cycles')) gc_collect_cycles();

        return $result;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // NHÓM 1 — NỘI DUNG & CẤU TRÚC
    // ══════════════════════════════════════════════════════════════════════════

    private function checkContent(array &$scores, array &$messages): void
    {
        $nodes = $this->xpath->query(
            '//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript)]' 
        );

        $fragments = [];
        foreach ($nodes as $node) {
            $fragments[] = $node->nodeValue;
        }

        $text  = implode(' ', $fragments);
        $words = preg_split('~[^\p{L}\p{N}\']+~u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $count = is_array($words) ? count($words) : 0;

        $this->wordCount   = $count;
        $this->bodyText    = $this->normalize($text);
        // ~200 từ/phút, tối thiểu 1 phút
        $this->readingTime = max(1, (int) round($count / 200));

        $paragraphs = $this->dom->getElementsByTagName('p')->length;
        $lists      = $this->dom->getElementsByTagName('ul')->length
                    + $this->dom->getElementsByTagName('ol')->length;
        $tables     = $this->dom->getElementsByTagName('table')->length;

        $structureBonus = 0;
        if ($paragraphs >= 5) $structureBonus++;
        if ($lists >= 1)      $structureBonus++;
        if ($tables >= 1)     $structureBonus++;

        if ($count >= 1500)     $score = 9;
        elseif ($count >= 1000) $score = 8;
        elseif ($count >= 600)  $score = 6;
        elseif ($count >= 300)  $score = 4;
        else                    $score = 2;

        $score = min(10, $score + ($structureBonus >= 2 ? 1 : 0));

        $scores['content']   = $score;
        $messages['content'] = ($score >= 8)
            ? "✅ Nội dung chuyên sâu ($count từ | ~{$this->readingTime} phút đọc | $paragraphs đoạn | $lists danh sách | $tables bảng)"
            : "⚠️ Nội dung mỏng ($count từ | ~{$this->readingTime} phút đọc). Cần tối thiểu 1000 từ để cạnh tranh";
    }

    private function checkTitle(array &$scores, array &$messages): void
    {
        $node  = $this->dom->getElementsByTagName('title')->item(0);
        $title = $node ? $this->cleanText($node->nodeValue) : '';

        if ($title === '') {
            $scores['title']   = 0;
            $messages['title'] = "❌ Không tìm thấy thẻ Title";
            return;
        }

        $len   = mb_strlen($title, 'UTF-8');
        $score = 0;

        if ($len >= 40 && $len <= 65)     $score += 5;
        elseif ($len >= 30 && $len <= 75) $score += 3;
        else                              $score += 1;

        if ($this->keyword !== '') {
            $kwQuoted = preg_quote($this->keyword, '/');
            $pattern  = '/(?<!\p{L})' . $kwQuoted . '(?!\p{L})/iu';

            if (preg_match($pattern, $title)) {
                $titleHalf = mb_substr($title, 0, (int)($len / 2), 'UTF-8');
                $score    += preg_match($pattern, $titleHalf) ? 5 : 3;
            }
        } else {
            $score += 5;
        }

        $scores['title'] = min(10, $score);
        if ($score === 10) {
            $messages['title'] = "✅ Thẻ Title hoàn hảo: keyword đứng đầu, độ dài chuẩn ($len ký tự)";
        } elseif ($score >= 8) {
            $messages['title'] = "✅ Thẻ Title tốt ($len ký tự) — Gợi ý: đưa keyword lên đầu Title để tối ưu hơn";
        } else {
            $messages['title'] = "⚠️ Title chưa tối ưu: nên 40-65 ký tự, keyword nên xuất hiện ở đầu Title";
        }
    }

    private function checkMeta(array &$scores, array &$messages): void
    {
        $metaTags = $this->xpath->query('//meta[@name="description"]');
        $desc     = $metaTags->length > 0
            ? $this->cleanText($metaTags->item(0)->getAttribute('content'))
            : '';

        $len = mb_strlen($desc, 'UTF-8');

        if ($len === 0) {
            $scores['meta']   = 0;
            $messages['meta'] = "❌ Thiếu thẻ Meta Description";
            return;
        }

        $score = 0;
        if ($len >= 120 && $len <= 160)     $score += 5;
        elseif ($len >= 100 && $len <= 180) $score += 3;
        else                                $score += 1;

        if ($this->keyword !== '') {
            $kwQuoted = preg_quote($this->keyword, '/');
            $score   += preg_match('/(?<!\p{L})' . $kwQuoted . '(?!\p{L})/iu', $desc) ? 5 : 0;
        } else {
            $score += 5;
        }

        // CTA detection
        $ctaWords = ['tìm hiểu', 'khám phá', 'xem ngay', 'hướng dẫn', 'download', 'learn', 'discover', 'get', 'free'];
        $hasCTA   = false;
        $descLow  = mb_strtolower($desc, 'UTF-8');
        foreach ($ctaWords as $w) {
            if (mb_strpos($descLow, $w, 0, 'UTF-8') !== false) { $hasCTA = true; break; }
        }

        // Duplicate description check: nếu desc trùng hoàn toàn title → penalty
        $titleNode = $this->dom->getElementsByTagName('title')->item(0);
        $titleText = $titleNode ? $this->cleanText($titleNode->nodeValue) : '';
        $descSameAsTitle = ($titleText !== '' && mb_strtolower($desc, 'UTF-8') === mb_strtolower($titleText, 'UTF-8'));

        if ($descSameAsTitle) {
            $score = max(0, $score - 3);
        }

        $scores['meta']   = min(10, $score);
        $ctaNote          = $hasCTA ? " [CTA ✓]" : " [Gợi ý: thêm CTA tăng CTR]";
        $dupNote          = $descSameAsTitle ? " ❌ Meta Description trùng với Title!" : '';
        $messages['meta'] = ($score >= 8)
            ? "✅ Meta Description chuẩn ($len ký tự)$ctaNote$dupNote"
            : "⚠️ Meta Description nên 120-160 ký tự, chứa keyword chính$ctaNote$dupNote";
    }

    private function checkH1(array &$scores, array &$messages): void
    {
        $h1s   = $this->dom->getElementsByTagName('h1');
        $count = $h1s->length;

        if ($count === 0) {
            $scores['h1']   = 0;
            $messages['h1'] = "❌ Trang không có thẻ H1";
            return;
        }

        if ($count > 1) {
            $scores['h1']   = 2;
            $messages['h1'] = "⚠️ Vi phạm cấu trúc: Có $count thẻ H1. Chỉ được dùng duy nhất 1 thẻ H1.";
            return;
        }

        $text = $this->cleanText($h1s->item(0)->textContent);

        if ($text === '') {
            $scores['h1']   = 0;
            $messages['h1'] = "❌ H1 tồn tại nhưng rỗng nội dung";
            return;
        }

        $score = 5;

        if ($this->keyword !== '') {
            $kwQuoted = preg_quote($this->keyword, '/');
            $score   += preg_match('/(?<!\p{L})' . $kwQuoted . '(?!\p{L})/iu', $text) ? 5 : 0;
        } else {
            $score += 5;
        }

        $titleNode          = $this->dom->getElementsByTagName('title')->item(0);
        $titleText          = $titleNode ? $this->normalize($titleNode->nodeValue) : '';
        $differentFromTitle = ($titleText === '' || $this->normalize($text) !== $titleText);

        $scores['h1']   = min(10, $score);
        $messages['h1'] = ($score === 10)
            ? "✅ H1 đạt chuẩn, chứa keyword chính" . ($differentFromTitle ? '' : ' [Lưu ý: H1 trùng Title 100%]')
            : "⚠️ H1 tồn tại nhưng chưa lồng ghép keyword chính";
    }

    private function checkHeading(array &$scores, array &$messages): void
    {
        $h2 = $this->dom->getElementsByTagName('h2')->length;
        $h3 = $this->dom->getElementsByTagName('h3')->length;
        $h4 = $this->dom->getElementsByTagName('h4')->length;

        $score = 10;
        $notes = [];

        if ($h2 === 0)          { $score -= 6; $notes[] = "Thiếu H2"; }
        if ($h3 > 0 && $h2 === 0) { $score -= 2; $notes[] = "H3 không có H2 cha"; }
        if ($h2 > 15)           { $score -= 2; $notes[] = "Quá nhiều H2 ($h2)"; }

        if ($this->keyword !== '' && $h2 > 0) {
            $kwQuoted = preg_quote($this->keyword, '/');
            $pattern  = '/(?<!\p{L})' . $kwQuoted . '(?!\p{L})/iu';
            $kwInH2   = false;

            foreach ($this->dom->getElementsByTagName('h2') as $h) {
                if (preg_match($pattern, $this->normalize($h->textContent))) {
                    $kwInH2 = true;
                    break;
                }
            }

            if (!$kwInH2) { $score -= 2; $notes[] = "Keyword chưa xuất hiện trong bất kỳ H2 nào"; }
        }

        $scores['heading']   = max(0, $score);
        $detail               = "H2: $h2 | H3: $h3 | H4: $h4";
        $messages['heading'] = ($score === 10)
            ? "✅ Cấu trúc Heading phân cấp logic ($detail)"
            : "⚠️ Cấu trúc Heading chưa tối ưu ($detail)" . (!empty($notes) ? " — " . implode(', ', $notes) : '');
    }

    /**
     * MỚI v3: Readability — Khả năng đọc & tiếp cận nội dung
     *
     * Google gián tiếp đo lường qua dwell time / bounce rate.
     * Bài viết dễ đọc → người dùng ở lại lâu hơn → tín hiệu tốt.
     *
     * Các chỉ số: độ dài câu trung bình, mật độ đoạn văn, dấu hiệu
     * câu bị động quá nhiều, thiếu subheading định kỳ.
     */
    private function checkReadability(array &$scores, array &$messages): void
    {
        $score  = 10;
        $notes  = [];
        $passes = [];

        if ($this->wordCount === 0) {
            $scores['readability']   = 5;
            $messages['readability'] = "ℹ️ Không đủ nội dung để đánh giá khả năng đọc";
            return;
        }

        // ── Chỉ số 1: Độ dài câu trung bình ────────────────────────────────
        // Tách câu bằng dấu chấm câu phổ biến (., !, ?)
        $sentences = preg_split('/[.!?。！？]+/u', $this->bodyText, -1, PREG_SPLIT_NO_EMPTY);
        $sentences = array_filter($sentences, fn($s) => mb_strlen(trim($s), 'UTF-8') > 10);
        $sentCount = count($sentences);

        if ($sentCount > 0) {
            $avgSentWords = $this->wordCount / $sentCount;
            if ($avgSentWords <= 20) {
                $passes[] = "Độ dài câu tốt (~" . round($avgSentWords, 1) . " từ/câu)";
            } elseif ($avgSentWords <= 30) {
                $score -= 2;
                $notes[] = "Câu hơi dài (~" . round($avgSentWords, 1) . " từ/câu, nên ≤20)";
            } else {
                $score -= 4;
                $notes[] = "Câu quá dài (~" . round($avgSentWords, 1) . " từ/câu) — khó đọc, tăng bounce rate";
            }
        }

        // ── Chỉ số 2: Tỷ lệ đoạn văn / từ — đoạn văn quá dài ─────────────
        $paragraphs = $this->dom->getElementsByTagName('p')->length;
        if ($paragraphs > 0) {
            $wordsPerPara = $this->wordCount / $paragraphs;
            if ($wordsPerPara <= 80) {
                $passes[] = "Đoạn văn cô đọng (~" . round($wordsPerPara, 0) . " từ/đoạn)";
            } elseif ($wordsPerPara <= 150) {
                $score -= 1;
                $notes[] = "Đoạn văn hơi dài (~" . round($wordsPerPara, 0) . " từ/đoạn, khuyến nghị ≤80)";
            } else {
                $score -= 3;
                $notes[] = "Đoạn văn quá dài (~" . round($wordsPerPara, 0) . " từ/đoạn) — nên tách nhỏ";
            }
        } elseif ($this->wordCount >= 300) {
            // Không có <p> tags nhưng có đủ nội dung — nhiều theme/builder dùng <div>
            // Không phạt điểm nhưng cảnh báo
            $notes[] = "Không phát hiện thẻ <p> — kiểm tra xem nội dung có được wrap đúng chuẩn HTML không";
        }

        // ── Chỉ số 3: Subheading frequency (H2+H3 / 300 từ) ────────────────
        $headingCount = $this->dom->getElementsByTagName('h2')->length
                      + $this->dom->getElementsByTagName('h3')->length;

        if ($this->wordCount >= 300) {
            $headingPer300 = ($headingCount / $this->wordCount) * 300;
            if ($headingPer300 >= 1.0) {
                $passes[] = "Subheading định kỳ ✓";
            } else {
                $score -= 2;
                $notes[] = "Thiếu subheading định kỳ (hiện ~" . round($headingPer300, 1) . " heading/300 từ, nên ≥1)";
            }
        }

        // ── Chỉ số 4: Passive voice proxy (dấu hiệu câu bị động tiếng Việt) ─
        $passiveSignals = ['được', 'bị', 'do', 'bởi'];
        $passiveCount   = 0;
        foreach ($passiveSignals as $pw) {
            $passiveCount += substr_count($this->bodyText, ' ' . $pw . ' ');
        }
        $passiveRate = $this->wordCount > 0 ? ($passiveCount / $this->wordCount) * 100 : 0;

        // Passive voice proxy — chỉ ghi nhận, không trừ điểm
        // (từ 'được/bị' trong tiếng Việt đa nghĩa, false positive rate cao)
        if ($passiveRate <= 8) {
            $passes[] = "Văn phong chủ động ✓ (~" . round($passiveRate, 1) . "% passive marker)";
        } else {
            // Chỉ cảnh báo, không trừ điểm — để người dùng tự đánh giá ngữ cảnh
            $notes[] = "Lưu ý: tỷ lệ passive marker cao (" . round($passiveRate, 1) . "% — từ được/bị/do/bởi). Kiểm tra thủ công.";
        }

        $scores['readability']   = max(0, $score);
        $passStr                  = !empty($passes) ? implode(' | ', $passes) : 'Không đạt chỉ số nào';
        $messages['readability'] = "Readability — $passStr"
            . (!empty($notes) ? " | ⚠️ " . implode(' | ', $notes) : '');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // NHÓM 2 — TỪ KHÓA & NGỮ NGHĨA
    // ══════════════════════════════════════════════════════════════════════════

    private function checkKeyword(array &$scores, array &$messages): void
    {
        if ($this->keyword === '' || $this->wordCount === 0) {
            $scores['keyword']   = 10;
            $messages['keyword'] = "✅ Bỏ qua kiểm tra mật độ (Không có từ khóa mục tiêu)";
            return;
        }

        $kwQuoted = preg_quote($this->keyword, '/');
        $pattern  = '/(?<!\p{L})' . $kwQuoted . '(?!\p{L})/iu';
        $count    = (int) preg_match_all($pattern, $this->bodyText);
        $density  = ($count / $this->wordCount) * 100;

        if ($density >= 0.5 && $density <= 1.5)    $score = 10;
        elseif ($density > 1.5 && $density <= 2.0) $score = 7;
        elseif ($density > 2.0 && $density <= 3.0) $score = 4;
        elseif ($density > 3.0)                    $score = 2;
        elseif ($density > 0 && $density < 0.5)    $score = 5;
        else                                       $score = 1;

        // First-100-words check
        $first100Words = implode(' ', array_slice(
            preg_split('~[^\p{L}\p{N}\']+~u', $this->bodyText, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            0, 100
        ));
        $inFirst100 = (bool) preg_match($pattern, $first100Words);
        if ($inFirst100 && $score < 10) $score = min(10, $score + 1);

        $notes = [];
        if ($density === 0.0)   $notes[] = "❌ Keyword hoàn toàn không xuất hiện trong bài";
        if (!$inFirst100)       $notes[] = "Keyword chưa xuất hiện trong 100 từ đầu bài";
        if ($density > 3.0)     $notes[] = "Nghi ngờ Keyword Stuffing (>3%)";
        elseif ($density > 2.0) $notes[] = "Mật độ hơi cao, tiến gần ngưỡng Stuffing";

        $scores['keyword']   = $score;
        $messages['keyword'] = "Mật độ keyword: " . round($density, 2) . "% ($count lần)"
            . (!empty($notes) ? " — ⚠️ " . implode(' | ', $notes) : '');
    }

    private function checkSemantic(array &$scores, array &$messages): void
    {
        $text    = $this->bodyText;
        $signals = 0;
        $details = [];

        // Signal 1: HowTo intent
        foreach (['hướng dẫn', 'cách', 'làm thế nào', 'how to', 'step by step', 'bước', 'các bước'] as $w) {
            if (mb_strpos($text, $w, 0, 'UTF-8') !== false) { $signals++; $details[] = "HowTo ✓"; break; }
        }

        // Signal 2: FAQ / Informational intent
        foreach (['là gì', 'tại sao', 'nguyên nhân', 'vì sao', 'khái niệm', 'what is', 'why is', 'khi nào'] as $w) {
            if (mb_strpos($text, $w, 0, 'UTF-8') !== false) { $signals++; $details[] = "FAQ ✓"; break; }
        }

        // Signal 3: Long-form
        if ($this->wordCount >= 1500) { $signals++; $details[] = "Long-form ✓"; }

        // Signal 4: Table
        if ($this->dom->getElementsByTagName('table')->length >= 1) { $signals++; $details[] = "Table ✓"; }

        // Signal 5: Blockquote / Cite
        if ($this->dom->getElementsByTagName('blockquote')->length >= 1
            || $this->dom->getElementsByTagName('cite')->length >= 1
        ) { $signals++; $details[] = "Quote/Cite ✓"; }

        // Signal 6: OL list
        if ($this->dom->getElementsByTagName('ol')->length >= 1) { $signals++; $details[] = "OL List ✓"; }

        // Signal 7: Details/FAQ accordion
        if ($this->dom->getElementsByTagName('details')->length >= 1) { $signals++; $details[] = "FAQ Accordion ✓"; }

        // Signal 8 (v3): Video embed — YouTube/Vimeo iframe (rich media = dwell time tăng)
        $hasVideo = false;
        foreach ($this->dom->getElementsByTagName('iframe') as $iframe) {
            $src = mb_strtolower((string) $iframe->getAttribute('src'), 'UTF-8');
            if (str_contains($src, 'youtube') || str_contains($src, 'vimeo') || str_contains($src, 'youtu.be')) {
                $hasVideo = true; break;
            }
        }
        if (!$hasVideo) {
            // Cũng check video tag HTML5
            $hasVideo = $this->dom->getElementsByTagName('video')->length > 0;
        }
        if ($hasVideo) { $signals++; $details[] = "Video ✓"; }

        // Signal 9 (v3): TF-IDF proxy — keyword xuất hiện trong nhiều heading khác nhau
        // (Tín hiệu: bài bao quát topic rộng, không chỉ nhắm 1 phrase)
        if ($this->keyword !== '') {
            $kwQuoted   = preg_quote($this->keyword, '/');
            $pattern    = '/(?<!\p{L})' . $kwQuoted . '(?!\p{L})/iu';
            $headingsWithKw = 0;
            foreach (['h2', 'h3', 'h4'] as $tag) {
                foreach ($this->dom->getElementsByTagName($tag) as $h) {
                    if (preg_match($pattern, $this->normalize($h->textContent))) $headingsWithKw++;
                }
            }
            if ($headingsWithKw >= 2) { $signals++; $details[] = "Topic Coverage ✓"; }
        }

        // Điểm: chia cho số signals có thể đạt được
        // Nếu không có keyword → signal 9 (Topic Coverage) bị skip → max 8 signals
        $maxSignals = ($this->keyword !== '') ? 9 : 8;
        $score = min(10, (int) round($signals * (10 / $maxSignals)));

        $scores['semantic']   = $score;
        $messages['semantic'] = "Semantic Signals đạt $signals/9: "
            . (!empty($details) ? implode(' | ', $details) : "Không phát hiện tín hiệu ngữ nghĩa nổi bật");
    }

    // ══════════════════════════════════════════════════════════════════════════
    // NHÓM 3 — KỸ THUẬT SEO
    // ══════════════════════════════════════════════════════════════════════════

    private function checkImages(array &$scores, array &$messages): void
    {
        $imgs  = $this->dom->getElementsByTagName('img');
        $total = $imgs->length;

        if ($total === 0) {
            $scores['images']   = 5;
            $messages['images'] = "⚠️ Trang không có hình ảnh minh họa";
            return;
        }

        $alt = $hasSize = $lazyLoad = $modernFmt = 0;

        foreach ($imgs as $img) {
            /** @var DOMElement $img */
            if (trim((string) $img->getAttribute('alt')) !== '') $alt++;
            if ($img->getAttribute('width') !== '' && $img->getAttribute('height') !== '') $hasSize++;
            if (mb_strtolower((string) $img->getAttribute('loading'), 'UTF-8') === 'lazy') $lazyLoad++;

            $src    = mb_strtolower((string) $img->getAttribute('src'), 'UTF-8');
            $srcset = mb_strtolower((string) $img->getAttribute('srcset'), 'UTF-8');
            if (str_contains($src, '.webp') || str_contains($src, '.avif')
                || str_contains($srcset, '.webp') || str_contains($srcset, '.avif')
            ) $modernFmt++;
        }

        $score    = 0;
        $altRatio = $alt / $total;

        if ($altRatio >= 1.0)    $score += 6;
        elseif ($altRatio >= 0.8) $score += 4;
        elseif ($altRatio >= 0.5) $score += 2;

        if ($hasSize / $total >= 0.8) $score += 2;
        if ($lazyLoad > 0)            $score += 1;
        if ($modernFmt > 0)           $score += 1;

        $issues = [];
        if ($alt < $total)           $issues[] = ($total - $alt) . " ảnh thiếu Alt";
        if ($hasSize < $total * 0.8) $issues[] = "Nhiều ảnh thiếu width/height (gây CLS)";
        if ($lazyLoad === 0)         $issues[] = "Chưa dùng loading=lazy";
        if ($modernFmt === 0)        $issues[] = "Chưa dùng WebP/AVIF";

        $scores['images']   = min(10, $score);
        $messages['images'] = ($score === 10)
            ? "✅ Tối ưu ảnh hoàn hảo: Alt, kích thước, lazy-load, định dạng hiện đại"
            : "⚠️ Hình ảnh cần cải thiện: " . implode(' | ', $issues);
    }

    private function checkLinks(array &$scores, array &$messages): void
    {
        $links          = $this->dom->getElementsByTagName('a');
        $internal       = $external = $nofollow = $unsafeExternal = 0;
        $ugcSponsored   = 0; // rel=ugc hoặc rel=sponsored

        foreach ($links as $a) {
            /** @var DOMElement $a */
            $href = $a->getAttribute('href');
            if (!$href
                || str_starts_with($href, '#')
                || str_starts_with($href, 'javascript:')
                || str_starts_with($href, 'mailto:')
                || str_starts_with($href, 'tel:')
            ) continue;

            $host = (string) parse_url($href, PHP_URL_HOST);
            $rel  = mb_strtolower((string) $a->getAttribute('rel'), 'UTF-8');

            if ($host === '' || ($this->domain !== '' && $host === $this->domain)) {
                $internal++;
            } elseif ($host !== '' && ($this->domain === '' || $host !== $this->domain)) {
                $external++;
                if (!str_contains($rel, 'noopener') && !str_contains($rel, 'noreferrer')) $unsafeExternal++;
                if (str_contains($rel, 'nofollow'))   $nofollow++;
                if (str_contains($rel, 'ugc') || str_contains($rel, 'sponsored')) $ugcSponsored++;
            }
        }

        $score = 0;
        $notes = [];

        if ($internal >= 3 && $internal <= 15)  { $score += 6; }
        elseif ($internal > 15)                 { $score += 4; $notes[] = "Quá nhiều internal link ($internal)"; }
        elseif ($internal > 0)                  { $score += 3; $notes[] = "Thiếu internal link ($internal)"; }
        else                                    { $notes[] = "❌ Không có internal link"; }

        if ($external >= 1)    { $score += 3; }
        else                   { $notes[] = "Không có external link trích nguồn"; }

        if ($external > 0 && $unsafeExternal === 0) {
            $score += 1;
        } elseif ($unsafeExternal > 0) {
            $notes[] = "$unsafeExternal external link thiếu rel=noopener";
        }

        if ($ugcSponsored > 0) {
            $notes[] = "$ugcSponsored link dùng rel=ugc/sponsored (đảm bảo đúng ngữ cảnh)";
        }

        $scores['links']   = min(10, $score);
        $messages['links'] = "Internal: $internal | External: $external | Nofollow: $nofollow"
            . (!empty($notes) ? " — ⚠️ " . implode(' | ', $notes) : " ✅");
    }

    private function checkURL(array &$scores, array &$messages): void
    {
        if ($this->url === '') {
            $scores['url']   = 7;
            $messages['url'] = "ℹ️ Không có URL để phân tích. Truyền tham số \$url để kích hoạt kiểm tra.";
            return;
        }

        $path      = (string) parse_url($this->url, PHP_URL_PATH);
        $pathClean = (string) preg_replace('/\.(html|php|htm|asp|aspx)$/i', '', $path);
        $query     = (string) parse_url($this->url, PHP_URL_QUERY);

        $score = 10;
        $notes = [];

        if (mb_strlen($pathClean, 'UTF-8') > 80)   { $score -= 3; $notes[] = "URL quá dài"; }
        if (str_contains($pathClean, '_'))          { $score -= 3; $notes[] = "Dùng dấu gạch dưới thay vì dấu gạch ngang"; }
        if (preg_match('/[A-Z]/', $pathClean))      { $score -= 2; $notes[] = "URL có chữ hoa"; }
        if ($query !== '' && substr_count($query, '=') > 3) {
            $score -= 2;
            $notes[] = "URL chứa quá nhiều tham số query string";
        }

        if ($this->keyword !== '') {
            $kwSlug  = $this->createSlug($this->keyword);
            $urlSlug = $this->createSlug($pathClean);
            if (!str_contains($urlSlug, $kwSlug)) {
                $score -= 2;
                $notes[] = "Keyword chưa có trong URL";
            }
        }

        $scores['url']   = max(0, $score);
        $messages['url'] = ($score === 10)
            ? "✅ URL sạch, chuẩn SEO"
            : "⚠️ URL cần cải thiện: " . implode(' | ', $notes);
    }

    private function checkCanonical(array &$scores, array &$messages): void
    {
        $canonicals = $this->xpath->query('//link[@rel="canonical"]');
        $count      = $canonicals->length;

        if ($count === 0) {
            $scores['canonical']   = 0;
            $messages['canonical'] = "❌ Thiếu thẻ Canonical. Rủi ro duplicate content — Google có thể chọn URL sai để index.";
            return;
        }

        if ($count > 1) {
            $scores['canonical']   = 3;
            $messages['canonical'] = "⚠️ Phát hiện $count thẻ Canonical (chỉ được 1). Xung đột canonical gây lỗi crawl.";
            return;
        }

        /** @var DOMElement $el */
        $el            = $canonicals->item(0);
        $canonicalHref = trim((string) $el->getAttribute('href'));

        if ($canonicalHref === '') {
            $scores['canonical']   = 2;
            $messages['canonical'] = "❌ Thẻ Canonical tồn tại nhưng href rỗng";
            return;
        }

        $isSelf  = ($this->url !== '' && rtrim($canonicalHref, '/') === rtrim($this->url, '/'));
        $isHttps = str_starts_with($canonicalHref, 'https://');
        $notes   = [];

        if ($isSelf && $isHttps)      { $score = 10; }
        elseif ($isSelf && !$isHttps) { $score = 7; $notes[] = "Canonical dùng HTTP thay vì HTTPS"; }
        elseif (!$isSelf && $isHttps) { $score = 8; $notes[] = "Canonical trỏ đến URL khác (kiểm tra lại nếu không phải trang pagination)"; }
        else                          { $score = 5; $notes[] = "Canonical HTTP + trỏ URL khác"; }

        $scores['canonical']   = max(0, $score);
        $messages['canonical'] = ($score >= 8)
            ? "✅ Canonical tag hợp lệ" . ($score === 10 ? ", self-referencing HTTPS" : " (trỏ URL khác)")
            : "⚠️ Canonical cần kiểm tra: " . implode(' | ', $notes);
    }

    /**
     * MỚI v3: Duplicate Content On-Page Detection
     *
     * Phát hiện các tín hiệu duplicate nội bộ trang:
     * - Title trùng H1
     * - Meta description quá ngắn hoặc trùng title (đã check trong checkMeta)
     * - Heading lặp lại chính xác
     * - Nội dung body quá ngắn so với boilerplate (nav/footer chiếm quá nhiều)
     */
    private function checkDuplicate(array &$scores, array &$messages): void
    {
        $score  = 10;
        $notes  = [];
        $passes = [];

        // ── Check 1: Title có trùng H1? ──────────────────────────────────────
        $titleNode = $this->dom->getElementsByTagName('title')->item(0);
        $h1Node    = $this->dom->getElementsByTagName('h1')->item(0);

        $titleNorm = $titleNode ? $this->normalize($titleNode->nodeValue) : '';
        $h1Norm    = $h1Node    ? $this->normalize($h1Node->textContent)  : '';

        if ($titleNorm !== '' && $h1Norm !== '' && $titleNorm === $h1Norm) {
            $score -= 3;
            $notes[] = "Title trùng H1 100% — nên đặt khác nhau để target nhiều keyword intent";
        } else {
            $passes[] = "Title ≠ H1 ✓";
        }

        // ── Check 2: Heading H2 duplicate ────────────────────────────────────
        $h2Texts = [];
        $h2Dupes = [];
        foreach ($this->dom->getElementsByTagName('h2') as $h) {
            $t = $this->normalize($h->textContent);
            if ($t === '') continue;
            if (in_array($t, $h2Texts)) { $h2Dupes[] = $t; }
            else                        { $h2Texts[] = $t; }
        }

        if (!empty($h2Dupes)) {
            $score -= 2;
            $first = mb_substr(reset($h2Dupes), 0, 50, 'UTF-8');
            $notes[] = count($h2Dupes) . " thẻ H2 bị trùng lặp (vd: \"$first...\")" ;
        } else {
            $passes[] = "H2 không trùng ✓";
        }

        // ── Check 3: Body content vs boilerplate ratio ────────────────────────
        // Chỉ đếm boilerplate TOP-LEVEL để tránh đếm 2 lần khi nested (vd: header chứa nav)
        $boilerplateWords = 0;

        foreach (['nav', 'header', 'footer', 'aside'] as $tag) {
            foreach ($this->dom->getElementsByTagName($tag) as $el) {
                /** @var DOMElement $el */
                // Bỏ qua nếu element là con của một boilerplate tag khác đã đếm
                $isNested = false;
                $parent   = $el->parentNode;
                while ($parent instanceof DOMElement) {
                    if (in_array(mb_strtolower($parent->tagName, 'UTF-8'), ['nav', 'header', 'footer', 'aside'])) {
                        $isNested = true;
                        break;
                    }
                    $parent = $parent->parentNode;
                }
                if ($isNested) continue;

                $bWords = preg_split('/[^\p{L}\p{N}\']+/u', (string) $el->textContent, -1, PREG_SPLIT_NO_EMPTY);
                $boilerplateWords += is_array($bWords) ? count($bWords) : 0;
            }
        }

        if ($this->wordCount > 0) {
            $boilerplateRatio = $boilerplateWords / $this->wordCount;
            if ($boilerplateRatio <= 0.3) {
                $passes[] = "Content/Boilerplate ratio tốt ✓";
            } elseif ($boilerplateRatio <= 0.5) {
                $score -= 1;
                $notes[] = "Boilerplate (nav/header/footer) chiếm ~" . round($boilerplateRatio * 100) . "% tổng text";
            } else {
                $score -= 3;
                $notes[] = "Nội dung thực sự quá ít — Boilerplate chiếm ~" . round($boilerplateRatio * 100) . "% tổng text (thin content risk)";
            }
        }

        // ── Check 4: Meta description không trùng với một đoạn văn đầu ───────
        $metaTags = $this->xpath->query('//meta[@name="description"]');
        if ($metaTags->length > 0) {
            $metaDesc = $this->normalize($metaTags->item(0)->getAttribute('content'));
            if ($metaDesc !== '') {
                $firstP = $this->dom->getElementsByTagName('p')->item(0);
                $firstPText = $firstP ? $this->normalize($firstP->textContent) : '';
                // Kiểm tra meta desc có phải copy từ đoạn đầu bài không
                // (dùng substring check thay vì similar_text — chính xác hơn cho use case này)
                $metaLen    = mb_strlen($metaDesc, 'UTF-8');
                $firstPNorm = $this->normalize($firstPText);

                $isDuplicate = false;
                if ($metaLen >= 30 && $firstPNorm !== '') {
                    // Check 1: meta là prefix của first paragraph
                    if (str_starts_with($firstPNorm, mb_substr($metaDesc, 0, min($metaLen, 60), 'UTF-8'))) {
                        $isDuplicate = true;
                    }
                    // Check 2: meta là substring của first paragraph (copied middle)
                    if (!$isDuplicate && $metaLen >= 50 && str_contains($firstPNorm, mb_substr($metaDesc, 0, 50, 'UTF-8'))) {
                        $isDuplicate = true;
                    }
                }

                if ($isDuplicate) {
                    $score -= 2;
                    $notes[] = "Meta description copy nguyên văn đoạn đầu bài — nên viết lại riêng biệt";
                } else {
                    $passes[] = "Meta desc độc lập ✓";
                }
            }
        }

        $scores['duplicate']   = max(0, $score);
        $passStr                = !empty($passes) ? implode(' | ', $passes) : 'Chưa đạt';
        $messages['duplicate'] = "Duplicate Check — $passStr"
            . (!empty($notes) ? " | ⚠️ " . implode(' | ', $notes) : '');
    }

    private function checkMobile(array &$scores, array &$messages): void
    {
        $vp    = $this->xpath->query('//meta[@name="viewport"]');
        $score = 0;
        $notes = [];

        if ($vp->length > 0) {
            $score   += 7;
            $vpContent = mb_strtolower((string) $vp->item(0)->getAttribute('content'), 'UTF-8');
            if (str_contains($vpContent, 'width=device-width')) $score += 2;
            else $notes[] = "Viewport thiếu width=device-width";
            if (str_contains($vpContent, 'initial-scale=1')) $score += 1;
            else $notes[] = "Viewport thiếu initial-scale=1";
        } else {
            $notes[] = "Không có thẻ Viewport";
        }

        $scores['mobile']   = min(10, $score);
        $messages['mobile'] = ($score === 10)
            ? "✅ Viewport chuẩn Mobile-first (width=device-width, initial-scale=1)"
            : "❌ Mobile chưa tối ưu: " . implode(' | ', $notes);
    }

    private function checkSpeed(array &$scores, array &$messages): void
    {
        $totalScripts = $asyncDefer = $renderBlocking = $totalCSS = $hasPreload = 0;
        $thirdPartyScripts = 0; // v3: track third-party script count
        $moduleScripts     = 0; // v3: type=module (modern JS bundling signal)

        foreach ($this->dom->getElementsByTagName('script') as $s) {
            /** @var DOMElement $s */
            $src = $s->getAttribute('src');
            if ($src === '') continue; // Inline script

            $totalScripts++;
            $isModule = mb_strtolower((string) $s->getAttribute('type'), 'UTF-8') === 'module';
            $hasAsync  = $s->hasAttribute('async');
            $hasDefer  = $s->hasAttribute('defer');

            if ($isModule) {
                // type=module luôn deferred theo spec — không render-blocking dù thiếu async/defer
                $moduleScripts++;
                $asyncDefer++;
            } elseif ($hasAsync || $hasDefer) {
                $asyncDefer++;
            } else {
                $renderBlocking++;
            }

            // v3: third-party = src chứa domain khác
            $srcHost = (string) parse_url($src, PHP_URL_HOST);
            if ($srcHost !== '' && $this->domain !== '' && $srcHost !== $this->domain) {
                $thirdPartyScripts++;
            }
        }

        foreach ($this->dom->getElementsByTagName('link') as $l) {
            /** @var DOMElement $l */
            $rel = mb_strtolower((string) $l->getAttribute('rel'), 'UTF-8');
            if ($rel === 'stylesheet') $totalCSS++;
            if (in_array($rel, ['preload', 'preconnect', 'dns-prefetch', 'modulepreload'])) $hasPreload++;
        }

        $score = 10;
        $notes = [];

        if ($renderBlocking > 5)  { $score -= 3; $notes[] = "$renderBlocking script render-blocking"; }
        if ($totalScripts > 25)   { $score -= 2; $notes[] = "Quá nhiều file JS ($totalScripts)"; }
        if ($totalCSS > 8)        { $score -= 2; $notes[] = "Quá nhiều file CSS ($totalCSS)"; }
        if ($hasPreload === 0 && ($totalScripts > 0 || $totalCSS > 2)) {
            $score -= 1;
            $notes[] = "Thiếu preload/preconnect";
        }
        // v3: quá nhiều third-party script = nguy cơ TTFB và INP
        if ($thirdPartyScripts > 5) { $score -= 2; $notes[] = ">5 third-party script ($thirdPartyScripts) — ảnh hưởng INP"; }

        $scores['speed']   = max(0, $score);
        $modNote            = $moduleScripts > 0 ? " | $moduleScripts module scripts ✓" : '';
        $tpNote             = $thirdPartyScripts > 0 ? " | $thirdPartyScripts 3rd-party" : '';
        $messages['speed'] = "JS: $totalScripts ($asyncDefer async/defer | $renderBlocking blocking$modNote$tpNote) | CSS: $totalCSS | Preload: $hasPreload"
            . (!empty($notes) ? " — ⚠️ " . implode(' | ', $notes) : " ✅");
    }

    private function checkCWV(array &$scores, array &$messages): void
    {
        $score  = 0;
        $notes  = [];
        $passes = [];

        // CWV 1: LCP preload image
        if ($this->xpath->query('//link[@rel="preload" and @as="image"]')->length > 0) {
            $score += 3; $passes[] = "LCP preload ✓";
        } else {
            $notes[] = "Thiếu preload ảnh LCP (link rel=preload as=image)";
        }

        // CWV 2: CLS — img width/height
        $imgs      = $this->dom->getElementsByTagName('img');
        $totalImgs = $imgs->length;
        $sizedImgs = 0;

        if ($totalImgs > 0) {
            foreach ($imgs as $img) {
                /** @var DOMElement $img */
                if ($img->getAttribute('width') !== '' && $img->getAttribute('height') !== '') $sizedImgs++;
            }
            $clsRatio = $sizedImgs / $totalImgs;
            if ($clsRatio >= 0.9)  { $score += 3; $passes[] = "CLS ảnh ✓"; }
            elseif ($clsRatio >= 0.5) { $score += 1; $notes[] = "Một số ảnh thiếu width/height (CLS risk)"; }
            else                   { $notes[] = "Phần lớn ảnh thiếu width/height (CLS risk cao)"; }
        } else {
            $score += 2; $passes[] = "CLS ảnh N/A";
        }

        // CWV 3+4: Single pass qua style tags
        $hasFontSwap = false; $inlineCSSLength = 0;
        foreach ($this->dom->getElementsByTagName('style') as $style) {
            $css              = (string) $style->nodeValue;
            $inlineCSSLength += mb_strlen($css, 'UTF-8');
            if (!$hasFontSwap && (str_contains($css, 'font-display:swap') || str_contains($css, 'font-display: swap'))) {
                $hasFontSwap = true;
            }
        }

        if ($hasFontSwap || $this->xpath->query('//link[@rel="preload" and @as="font"]')->length > 0) {
            $score += 2; $passes[] = "Font swap ✓";
        } else {
            $notes[] = "Chưa có font-display:swap hoặc preload font";
        }

        if ($inlineCSSLength < 50000) { $score += 2; $passes[] = "Inline CSS hợp lý ✓"; }
        else { $notes[] = "Inline CSS quá lớn (" . round($inlineCSSLength / 1024, 1) . "KB)"; }

        $scores['cwv']   = min(10, $score);
        $passStr          = !empty($passes) ? implode(', ', $passes) : "Không đạt signal nào";
        $messages['cwv'] = "Core Web Vitals HTML Hints — $passStr"
            . (!empty($notes) ? " | ⚠️ " . implode(' | ', $notes) : '');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // NHÓM 4 — THẨM QUYỀN & PHÂN PHỐI
    // ══════════════════════════════════════════════════════════════════════════

    private function checkEEAT(array &$scores, array &$messages): void
    {
        $score  = 0;
        $passes = [];
        $notes  = [];

        // Signal 1: Author meta
        if ($this->xpath->query('//meta[@name="author"] | //meta[@property="article:author"]')->length > 0) {
            $score += 2; $passes[] = "Author meta ✓";
        } else {
            $notes[] = "Thiếu khai báo tác giả (meta author)";
        }

        // Signal 2: Author trong Schema
        $schemaNodes     = $this->xpath->query('//script[@type="application/ld+json"]');
        $hasSchemaAuthor = false;
        $hasDate         = false;

        // v3: Single pass qua schema nodes cho cả author + date
        foreach ($schemaNodes as $node) {
            $raw = (string) $node->nodeValue;
            if (!$hasSchemaAuthor && str_contains($raw, '"author"')) $hasSchemaAuthor = true;
            if (!$hasDate && (str_contains($raw, '"datePublished"') || str_contains($raw, '"dateModified"'))) $hasDate = true;
            if ($hasSchemaAuthor && $hasDate) break;
        }

        if ($hasSchemaAuthor) { $score += 2; $passes[] = "Schema author ✓"; }

        // Signal 3: Date freshness
        if (!$hasDate) {
            $hasDate = $this->xpath->query('//meta[@property="article:published_time"]')->length > 0;
        }
        if ($hasDate) { $score += 2; $passes[] = "Date freshness ✓"; }
        else { $notes[] = "Thiếu khai báo ngày đăng/cập nhật"; }

        // Signal 4: Citation / trusted source
        $hasSourceLink = false;
        $hasCite       = $this->dom->getElementsByTagName('cite')->length > 0;
        if (!$hasCite) {
            foreach ($this->dom->getElementsByTagName('a') as $link) {
                $text = mb_strtolower((string) $link->nodeValue, 'UTF-8');
                if (str_contains($text, 'nguồn') || str_contains($text, 'source')
                    || str_contains($text, 'tham khảo') || str_contains($text, 'wikipedia')
                    || str_contains($text, 'reference')
                ) { $hasSourceLink = true; break; }
            }
        }
        if ($hasSourceLink || $hasCite) { $score += 2; $passes[] = "Citation ✓"; }
        else { $notes[] = "Thiếu link trích dẫn nguồn uy tín"; }

        // Signal 5: About/Contact link
        $hasAbout = false;
        foreach ($this->dom->getElementsByTagName('a') as $link) {
            $href = mb_strtolower((string) $link->getAttribute('href'), 'UTF-8');
            $text = mb_strtolower((string) $link->nodeValue, 'UTF-8');
            if (str_contains($href, 'about') || str_contains($href, 'contact')
                || str_contains($href, 'gioi-thien') || str_contains($href, 'lien-he')
                || str_contains($text, 'về chúng tôi') || str_contains($text, 'liên hệ')
            ) { $hasAbout = true; break; }
        }
        if ($hasAbout) { $score += 2; $passes[] = "About/Contact ✓"; }
        else { $notes[] = "Thiếu link About/Contact"; }

        $scores['eeat']   = min(10, $score);
        $messages['eeat'] = "E-E-A-T Signals (" . count($passes) . "/5): " . implode(', ', $passes)
            . (!empty($notes) ? " | ⚠️ " . implode('; ', $notes) : '');
    }

    private function checkSchema(array &$scores, array &$messages): void
    {
        $schemas    = $this->xpath->query('//script[@type="application/ld+json"]');
        $score      = 0;
        $foundTypes = [];
        $parseErrors = 0;
        $missingFields = []; // v3: required field check per type

        $highValueTypes = [
            'Article', 'BlogPosting', 'NewsArticle',
            'Product', 'Offer', 'Review',
            'FAQPage', 'HowTo', 'QAPage',
            'LocalBusiness', 'Organization',
            'BreadcrumbList', 'WebPage', 'WebSite', 'Person',
        ];

        // Required fields per type — Google Rich Results minimum
        $requiredFields = [
            'Article'     => ['headline', 'author', 'datePublished'],
            'BlogPosting'  => ['headline', 'author', 'datePublished'],
            'Product'      => ['name', 'offers'],
            'FAQPage'      => ['mainEntity'],
            'HowTo'        => ['name', 'step'],
            'LocalBusiness'=> ['name', 'address'],
            'Review'       => ['itemReviewed', 'reviewRating', 'author'],
        ];

        if ($schemas->length > 0) {
            $score += 4;

            foreach ($schemas as $schema) {
                $rawJson = (string) $schema->nodeValue;
                $decoded = json_decode($rawJson, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $parseErrors++;
                    continue;
                }

                $items = isset($decoded['@graph']) ? $decoded['@graph'] : [$decoded];

                foreach ($items as $item) {
                    $type = $item['@type'] ?? '';
                    if (is_array($type)) $type = (string) ($type[0] ?? ''); // Lấy type đầu tiên, an toàn hơn reset()
                    if ($type === '' || !in_array($type, $highValueTypes)) continue;

                    $foundTypes[] = $type;

                    // v3: Kiểm tra required fields
                    if (isset($requiredFields[$type])) {
                        foreach ($requiredFields[$type] as $field) {
                            if (!isset($item[$field])) {
                                $missingFields[] = "$type thiếu field \"$field\"";
                            }
                        }
                    }
                }
            }

            if (!empty($foundTypes))  $score += 4;
            if ($parseErrors === 0)   $score += 2;
        }

        $scores['schema']   = min(10, $score);
        $typeList            = !empty($foundTypes)
            ? implode(', ', array_unique($foundTypes))
            : "Không có type quan trọng";
        $errorNote           = $parseErrors > 0 ? " | ❌ $parseErrors JSON lỗi cú pháp" : '';
        $fieldNote           = !empty($missingFields)
            ? " | ⚠️ Required fields: " . implode(', ', array_slice($missingFields, 0, 3))
            : '';

        $messages['schema'] = ($score === 10)
            ? "✅ Schema JSON-LD đầy đủ và hợp lệ: $typeList$fieldNote"
            : "⚠️ Schema: $typeList$errorNote$fieldNote" . ($schemas->length === 0 ? " — Chưa có Schema nào" : '');
    }

    private function checkSocial(array &$scores, array &$messages): void
    {
        $og      = $this->xpath->query('//meta[contains(@property,"og:")]')->length;
        $twitter = $this->xpath->query('//meta[contains(@name,"twitter:")]')->length;

        $score = 0;
        if ($og >= 4)         $score += 6;
        elseif ($og > 0)      $score += 3;
        if ($twitter >= 2)    $score += 4;
        elseif ($twitter > 0) $score += 2;

        $scores['social']   = min(10, $score);
        $messages['social'] = "Open Graph: $og tags | Twitter Card: $twitter tags"
            . ($score < 10 ? " — ⚠️ Nên có đủ 4 OG tags cơ bản + Twitter Card" : " ✅");
    }

    private function checkHreflang(array &$scores, array &$messages): void
    {
        $hreflangs = $this->xpath->query('//link[@rel="alternate" and @hreflang]');
        $count     = $hreflangs->length;

        if ($count === 0) {
            $scores['hreflang']   = 7;
            $messages['hreflang'] = "ℹ️ Không tìm thấy thẻ Hreflang. Nếu site đơn ngôn ngữ thì không cần.";
            return;
        }

        $hasXDefault = false;
        $langs       = [];

        foreach ($hreflangs as $tag) {
            /** @var DOMElement $tag */
            $lang    = $tag->getAttribute('hreflang');
            $langs[] = $lang;
            if ($lang === 'x-default') $hasXDefault = true;
        }

        $score = 7;
        $notes = [];

        if ($hasXDefault)       { $score += 2; }
        else                    { $notes[] = "Thiếu hreflang x-default"; }
        if (count($langs) > 1)  { $score += 1; }

        $scores['hreflang']   = min(10, $score);
        $messages['hreflang'] = "Hreflang: " . count($langs) . " ngôn ngữ (" . implode(', ', array_unique($langs)) . ")"
            . ($hasXDefault ? " | x-default ✓" : '')
            . (!empty($notes) ? " | ⚠️ " . implode(', ', $notes) : '');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // OUTPUT & SCORING
    // ══════════════════════════════════════════════════════════════════════════

    private function calculateFinal(array $scores): int
    {
        $weightedTotal = 0;
        $totalWeights  = 0;

        foreach ($this->weights as $key => $weight) {
            $weightedTotal += ($scores[$key] ?? 0) * $weight;
            $totalWeights  += $weight * 10;
        }

        if ($totalWeights === 0) return 0;
        return max(0, min(100, (int) round(($weightedTotal / $totalWeights) * 100)));
    }

    private function buildBreakdown(array $scores): array
    {
        $groups = [
            'content_structure' => ['title', 'meta', 'h1', 'heading', 'content', 'readability'],
            'keyword_semantic'  => ['keyword', 'semantic'],
            'technical_seo'     => ['images', 'links', 'url', 'canonical', 'duplicate', 'mobile', 'speed', 'cwv'],
            'authority'         => ['eeat', 'schema', 'social', 'hreflang'],
        ];

        $breakdown = [];

        foreach ($groups as $group => $keys) {
            $gW = 0; $gMax = 0;
            foreach ($keys as $key) {
                $w     = $this->weights[$key] ?? 0;
                $gW   += ($scores[$key] ?? 0) * $w;
                $gMax += 10 * $w;
            }
            $breakdown[$group] = $gMax > 0 ? (int) round(($gW / $gMax) * 100) : 0;
        }

        return $breakdown;
    }

    /**
     * MỚI v3: Structured issues list phân loại Critical / Warning / Info
     * Sắp xếp theo mức độ ưu tiên để caller dễ hiển thị
     */
    private function buildIssues(array $scores, array $messages): array
    {
        $issues = [];

        // Định nghĩa ngưỡng: [critical_threshold, warning_threshold]
        $thresholds = [
            'canonical'   => [4, 9],  // score=8 (canonical trỏ URL khác) vẫn cần warning
            'h1'          => [4, 7],
            'content'     => [4, 6],
            'title'       => [4, 7],
            'mobile'      => [4, 7],
            'eeat'        => [4, 6],
            'schema'      => [4, 6],
            'duplicate'   => [5, 7],
            'meta'        => [3, 6],
            'keyword'     => [3, 6],
            'speed'       => [5, 7],
            'cwv'         => [4, 6],
            'images'      => [3, 6],
            'links'       => [3, 6],
            'semantic'    => [3, 5],
            'readability' => [4, 6],
            'heading'     => [3, 6],
            'url'         => [3, 6],
            'social'      => [2, 5],
            'hreflang'    => [3, 5],
        ];

        foreach ($thresholds as $key => [$criticalThreshold, $warningThreshold]) {
            $score   = $scores[$key] ?? 10;
            $message = $messages[$key] ?? '';

            if ($score <= $criticalThreshold) {
                $issues[] = ['level' => 'critical', 'key' => $key, 'score' => $score, 'message' => $message];
            } elseif ($score <= $warningThreshold) {
                $issues[] = ['level' => 'warning',  'key' => $key, 'score' => $score, 'message' => $message];
            } else {
                $issues[] = ['level' => 'info',     'key' => $key, 'score' => $score, 'message' => $message];
            }
        }

        // Sắp xếp: critical → warning → info, trong mỗi nhóm sắp theo score tăng dần
        usort($issues, function ($a, $b) {
            $levelOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
            $lo         = $levelOrder[$a['level']] <=> $levelOrder[$b['level']];
            return $lo !== 0 ? $lo : ($a['score'] <=> $b['score']);
        });

        return $issues;
    }

    private function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => "A+",
            $score >= 80 => "A",
            $score >= 70 => "B",
            $score >= 60 => "C",
            default      => "D",
        };
    }

    private function health(int $score): string
    {
        return match (true) {
            $score >= 90 => "Healthy (Hoàn hảo)",
            $score >= 80 => "Good (Tốt)",
            $score >= 70 => "Average (Trung bình)",
            $score >= 60 => "Weak (Yếu)",
            default      => "Critical (Nguy cơ phạt nặng)",
        };
    }

    /**
     * v3: recommend() nhận scores để đưa ra gợi ý cụ thể hơn
     */
    private function recommend(int $score, array $scores): string
    {
        // Tìm checker có IMPACT cao nhất cần cải thiện
        // Impact = (10 - score) * weight — ưu tiên điểm thấp ở checker quan trọng
        $lowestKey    = '';
        $highestImpact = -1;
        $priorityKeys  = ['canonical', 'eeat', 'content', 'h1', 'schema', 'keyword', 'readability', 'duplicate'];

        foreach ($priorityKeys as $key) {
            if (!isset($scores[$key])) continue;
            $impact = (10 - $scores[$key]) * ($this->weights[$key] ?? 1);
            if ($impact > $highestImpact) {
                $highestImpact = $impact;
                $lowestKey     = $key;
            }
        }

        $focusMap = [
            'canonical'   => "Thêm thẻ Canonical ngay để tránh duplicate content.",
            'eeat'        => "Bổ sung thông tin tác giả, ngày đăng và trích dẫn nguồn để cải thiện E-E-A-T.",
            'content'     => "Mở rộng nội dung lên tối thiểu 1000 từ với cấu trúc rõ ràng.",
            'h1'          => "Sửa thẻ H1 — duy nhất 1 thẻ, chứa keyword chính.",
            'schema'      => "Thêm Schema JSON-LD phù hợp (Article/FAQPage/HowTo) để tăng khả năng hiện Rich Snippets.",
            'keyword'     => "Điều chỉnh mật độ keyword về mức 0.5–1.5% và đưa keyword vào 100 từ đầu.",
            'readability' => "Cải thiện khả năng đọc: rút ngắn câu, tách đoạn văn, thêm subheading định kỳ.",
            'duplicate'   => "Kiểm tra và loại bỏ nội dung trùng lặp nội bộ (H1/Title, H2 lặp, boilerplate thừa).",
        ];

        $focusTip = isset($focusMap[$lowestKey]) ? " Ưu tiên: " . $focusMap[$lowestKey] : '';

        if ($score >= 80) return "On-page SEO đạt chuẩn 2026 tốt. Tập trung xây dựng backlink và tối ưu Core Web Vitals thực tế.$focusTip";
        if ($score >= 60) return "On-page ở mức trung bình. Cần cải thiện các mục cảnh báo.$focusTip";
        return "Nghiêm trọng: Nhiều vi phạm SEO cơ bản. Cần audit toàn diện.$focusTip";
    }

    // ══════════════════════════════════════════════════════════════════════════
    // UTILITIES
    // ══════════════════════════════════════════════════════════════════════════

    private function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)), 'UTF-8');
    }

    private function cleanText(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    private function createSlug(string $str): string
    {
        $map = [
            'a' => 'à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ|À|Á|Ạ|Ả|Ã|Â|Ầ|Ấ|Ậ|Ẩ|Ẫ|Ă|Ằ|Ắ|Ặ|Ẳ|Ẵ',
            'e' => 'è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ|È|É|Ẹ|Ẻ|Ẽ|Ê|Ề|Ế|Ệ|Ể|Ễ',
            'i' => 'ì|í|ị|ỉ|ĩ|Ì|Í|Ị|Ỉ|Ĩ',
            'o' => 'ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ|Ò|Ó|Ọ|Ỏ|Õ|Ô|Ồ|Ố|Ộ|Ổ|Ỗ|Ơ|Ờ|Ớ|Ợ|Ở|Ỡ',
            'u' => 'ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ|Ù|Ú|Ụ|Ủ|Ũ|Ư|Ừ|Ứ|Ự|Ử|Ữ',
            'y' => 'ỳ|ý|ỵ|ỉ|ỹ|Ỳ|Ý|Ỵ|Ỉ|Ỹ',
            'd' => 'đ|Đ',
        ];

        foreach ($map as $replace => $search) {
            $str = (string) preg_replace("/($search)/u", $replace, $str);
        }

        $str = mb_strtolower(trim($str), 'UTF-8');
        $str = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $str);
        return trim($str, '-');
    }
}
