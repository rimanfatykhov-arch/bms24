<?php
namespace local_kbaai\provider;
defined('MOODLE_INTERNAL') || die();

/**
 * Defensive parser for OpenAI-compatible multimodal responses.
 *
 * Yandex AI Studio chat-completions normally returns message.content as a
 * string, but this parser intentionally accepts several content layouts so
 * upgrades/provider-side changes do not silently turn a valid answer into an
 * empty one.
 */
class multimodal_response_parser {

    /**
     * Parse a provider response.
     *
     * @param array $data decoded JSON response
     * @return array diagnostics plus extracted final text
     */
    public static function parse(array $data) {
        $result = array(
            'text' => '',
            'path' => '',
            'content_type' => '',
            'finish_reason' => '',
            'refusal' => '',
            'reasoning_content_present' => false,
            'reasoning_tokens' => 0,
            'response_keys' => implode(', ', array_keys($data)),
        );

        if (!empty($data['choices'][0]) && is_array($data['choices'][0])) {
            $choice = $data['choices'][0];
            if (isset($choice['finish_reason']) && is_scalar($choice['finish_reason'])) {
                $result['finish_reason'] = (string)$choice['finish_reason'];
            }
            if (!empty($choice['message']) && is_array($choice['message'])) {
                $message = $choice['message'];
                if (isset($message['refusal']) && is_scalar($message['refusal'])) {
                    $result['refusal'] = trim((string)$message['refusal']);
                }
                if (isset($message['reasoning_content']) && trim((string)$message['reasoning_content']) !== '') {
                    $result['reasoning_content_present'] = true;
                }
                if (array_key_exists('content', $message)) {
                    $parsed = self::parse_content_value($message['content']);
                    if ($parsed['text'] !== '') {
                        $result['text'] = $parsed['text'];
                        $result['path'] = 'choices[0].message.content' . $parsed['suffix'];
                        $result['content_type'] = $parsed['type'];
                    } else {
                        $result['content_type'] = $parsed['type'];
                    }
                }
                if ($result['text'] === '' && isset($message['text']) && is_scalar($message['text'])) {
                    $result['text'] = trim((string)$message['text']);
                    $result['path'] = 'choices[0].message.text';
                    $result['content_type'] = gettype($message['text']);
                }
            }
            if ($result['text'] === '' && isset($choice['text']) && is_scalar($choice['text'])) {
                $result['text'] = trim((string)$choice['text']);
                $result['path'] = 'choices[0].text';
                $result['content_type'] = gettype($choice['text']);
            }
        }

        // Responses-API-compatible convenience field. Keeping this fallback
        // costs nothing and makes diagnostics useful if the endpoint evolves.
        if ($result['text'] === '' && isset($data['output_text']) && is_scalar($data['output_text'])) {
            $result['text'] = trim((string)$data['output_text']);
            $result['path'] = 'output_text';
            $result['content_type'] = gettype($data['output_text']);
        }

        if ($result['text'] === '' && !empty($data['output']) && is_array($data['output'])) {
            $texts = array();
            foreach ($data['output'] as $outputindex => $item) {
                if (!is_array($item) || empty($item['content']) || !is_array($item['content'])) {
                    continue;
                }
                foreach ($item['content'] as $contentindex => $part) {
                    if (!is_array($part)) {
                        continue;
                    }
                    foreach (array('text', 'output_text', 'content') as $key) {
                        if (isset($part[$key]) && is_scalar($part[$key]) && trim((string)$part[$key]) !== '') {
                            $texts[] = trim((string)$part[$key]);
                            if ($result['path'] === '') {
                                $result['path'] = 'output[' . $outputindex . '].content[' . $contentindex . '].' . $key;
                            }
                        }
                    }
                }
            }
            if ($texts) {
                $result['text'] = trim(implode("\n", $texts));
                $result['content_type'] = 'array';
            }
        }

        if (!empty($data['usage']['completion_tokens_details']['reasoning_tokens'])) {
            $result['reasoning_tokens'] = (int)$data['usage']['completion_tokens_details']['reasoning_tokens'];
        } else if (!empty($data['usage']['completionTokensDetails']['reasoningTokens'])) {
            $result['reasoning_tokens'] = (int)$data['usage']['completionTokensDetails']['reasoningTokens'];
        }

        return $result;
    }

    /**
     * @param mixed $content
     * @return array
     */
    private static function parse_content_value($content) {
        if (is_string($content) || is_numeric($content)) {
            return array('text' => trim((string)$content), 'suffix' => '', 'type' => gettype($content));
        }
        if ($content === null) {
            return array('text' => '', 'suffix' => '', 'type' => 'null');
        }
        if (!is_array($content)) {
            return array('text' => '', 'suffix' => '', 'type' => gettype($content));
        }

        $texts = array();
        $firstsuffix = '';
        foreach ($content as $index => $part) {
            if (is_string($part) || is_numeric($part)) {
                $value = trim((string)$part);
                if ($value !== '') {
                    $texts[] = $value;
                    if ($firstsuffix === '') {
                        $firstsuffix = '[' . $index . ']';
                    }
                }
                continue;
            }
            if (!is_array($part)) {
                continue;
            }
            foreach (array('text', 'output_text', 'content') as $key) {
                if (isset($part[$key]) && is_scalar($part[$key])) {
                    $value = trim((string)$part[$key]);
                    if ($value !== '') {
                        $texts[] = $value;
                        if ($firstsuffix === '') {
                            $firstsuffix = '[' . $index . '].' . $key;
                        }
                    }
                }
            }
        }
        return array('text' => trim(implode("\n", $texts)), 'suffix' => $firstsuffix, 'type' => 'array');
    }
}
