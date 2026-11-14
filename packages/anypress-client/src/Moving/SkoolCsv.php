<?php

namespace AnyPressClient\Moving;

/**
 * The members CSV a Skool group's admin downloads from the group's Members tab (Export). Skool doesn't publish the
 * file's layout, so the columns are found by their headers, whatever their case or spacing: the email ("Email"), the
 * name ("FirstName" and "LastName", or "Name"), and each membership question with its answer ("Question1" and
 * "Answer1", and so on, where the question column holds the question's text). Any other column (such as the join date,
 * tier or lifetime value) is listed as left out.
 */
class SkoolCsv
{
    /** @var array<int, array{email: string, first: string, last: string, name: string, answers: array<string, string>}> */
    public array $members = [];
    /** @var int[] Row numbers (the header is row 1) of members without a usable email. */
    public array $no_email = [];
    /** Rows whose email came up again further down (counted once). */
    public int $repeats = 0;
    /** @var string[] Headers that aren't read. */
    public array $left_out = [];
    /** @var string[] Every membership question met, in order. */
    public array $questions = [];
    /** Why the file can't be read, or ''. */
    public string $problem = '';

    public function __construct(string $csv)
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? '';
        $rows = self::rows($csv);
        if (!$rows) {
            $this->problem = __('The CSV file is empty.', 'anypress-client');
            return;
        }
        $columns = $this->columns(array_shift($rows));
        if (!isset($columns['email'])) {
            $this->problem = __('This CSV has no email column. Download it again from the Skool group\'s Members tab (Export): Skool includes emails for paid groups, and Skool support can send a full list for a free group.', 'anypress-client');
            return;
        }
        $seen = [];
        foreach ($rows as $index => $cells) {
            if (!array_filter($cells, fn ($cell) => trim($cell) !== '')) {
                continue;
            }
            $email = strtolower(trim((string) ($cells[$columns['email']] ?? '')));
            if (!is_email($email)) {
                $this->no_email[] = $index + 2;
                continue;
            }
            if (isset($seen[$email])) {
                $this->repeats++;
                continue;
            }
            $seen[$email] = true;
            $answers = [];
            foreach ($columns['pairs'] as [$question, $answer]) {
                $asked = sanitize_text_field((string) ($cells[$question] ?? ''));
                $said = sanitize_textarea_field((string) ($cells[$answer] ?? ''));
                if ($asked !== '' && $said !== '') {
                    $answers[$asked] = $said;
                    if (!in_array($asked, $this->questions, true)) {
                        $this->questions[] = $asked;
                    }
                }
            }
            $this->members[] = [
                'email' => $email,
                'first' => sanitize_text_field((string) ($cells[$columns['first'] ?? -1] ?? '')),
                'last' => sanitize_text_field((string) ($cells[$columns['last'] ?? -1] ?? '')),
                'name' => sanitize_text_field((string) ($cells[$columns['name'] ?? -1] ?? '')),
                'answers' => $answers,
            ];
        }
    }

    /** Header cells to column numbers: email, first, last, name, and pairs of [question column, answer column]. */
    private function columns(array $header): array
    {
        $columns = ['pairs' => []];
        $questions = [];
        $answers = [];
        foreach ($header as $at => $title) {
            $key = preg_replace('/[^a-z0-9]/', '', strtolower((string) $title));
            if (in_array($key, ['email', 'emailaddress', 'memberemail'], true)) {
                $columns['email'] = $at;
            } elseif (in_array($key, ['firstname', 'first'], true)) {
                $columns['first'] = $at;
            } elseif (in_array($key, ['lastname', 'last', 'surname'], true)) {
                $columns['last'] = $at;
            } elseif (in_array($key, ['name', 'fullname', 'membername'], true)) {
                $columns['name'] = $at;
            } elseif (preg_match('/^question(\d+)$/', $key, $match)) {
                $questions[(int) $match[1]] = $at;
            } elseif (preg_match('/^answer(\d+)$/', $key, $match)) {
                $answers[(int) $match[1]] = $at;
            } elseif (trim((string) $title) !== '') {
                $this->left_out[] = sanitize_text_field((string) $title);
            }
        }
        ksort($questions);
        foreach ($questions as $n => $at) {
            if (isset($answers[$n])) {
                $columns['pairs'][] = [$at, $answers[$n]];
            }
        }

        return $columns;
    }

    /**
     * The file's rows as lists of cells. Splits on line breaks outside quotes only, so an answer written over several
     * lines stays one cell, then reads each row with PHP's CSV reader.
     *
     * @return array<int, string[]>
     */
    private static function rows(string $csv): array
    {
        $rows = [];
        $line = '';
        $quoted = false;
        $length = strlen($csv);
        for ($i = 0; $i < $length; $i++) {
            $char = $csv[$i];
            if ($char === '"') {
                $quoted = !$quoted;
            }
            if (!$quoted && ($char === "\n" || $char === "\r")) {
                if ($char === "\r" && ($csv[$i + 1] ?? '') === "\n") {
                    $i++;
                }
                $rows[] = $line;
                $line = '';
                continue;
            }
            $line .= $char;
        }
        if ($line !== '') {
            $rows[] = $line;
        }
        $rows = array_values(array_filter($rows, fn ($row) => trim($row) !== ''));

        return array_map(fn ($row) => array_map('strval', str_getcsv($row, ',', '"', '')), $rows);
    }
}
