<?php
/**
 * Copyright GenMagic. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace GenMagic\ProductPhotos\Model;

/**
 * The photo styles and the prompt each one sends. Every style tells the service to keep the product exactly as it is.
 */
class PhotoStyles
{
    public const MAX_EXTRA_LENGTH = 300;
    public const DEFAULT_STYLE = 'studio';

    private const KEEP = 'Keep the product itself exactly as in the photo: same shape, colors, materials, label, '
        . 'logo and proportions.';

    /**
     * Style ids with their prompts.
     *
     * @return array<string, string>
     */
    private function prompts(): array
    {
        return [
            'studio' => 'Professional e-commerce product photo on a clean white seamless background, '
                . 'soft studio lighting, gentle shadow. ' . self::KEEP,
            'lifestyle' => 'Lifestyle product photo of this product in a tasteful real-life setting where it is '
                . 'used, natural light, shallow depth of field. ' . self::KEEP,
            'model' => 'A person naturally using or wearing this product in a bright, modern setting, '
                . 'editorial photo. ' . self::KEEP,
            'flatlay' => 'Top-down flat lay product photo with complementary props on a styled surface, '
                . 'even soft light. ' . self::KEEP,
            'seasonal' => 'Seasonal holiday product photo with festive but uncluttered decoration around the '
                . 'product, warm light. ' . self::KEEP,
        ];
    }

    /**
     * Style ids with their labels, for the form.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function getOptions(): array
    {
        $labels = [
            'studio' => (string)__('White studio'),
            'lifestyle' => (string)__('Lifestyle scene'),
            'model' => (string)__('With a model'),
            'flatlay' => (string)__('Flat lay'),
            'seasonal' => (string)__('Seasonal'),
        ];
        $options = [];
        foreach ($labels as $id => $label) {
            $options[] = ['id' => $id, 'label' => $label];
        }
        return $options;
    }

    /**
     * Whether the id is one of the styles.
     *
     * @param string $style
     * @return bool
     */
    public function isValid(string $style): bool
    {
        return array_key_exists($style, $this->prompts());
    }

    /**
     * The prompt for a style plus the merchant's own words (at most 300 characters).
     *
     * @param string $style
     * @param string $extra
     * @return string
     */
    public function buildPrompt(string $style, string $extra): string
    {
        $prompts = $this->prompts();
        $base = $prompts[$style] ?? $prompts[self::DEFAULT_STYLE];
        $more = $this->cleanExtra($extra);
        return $more !== '' ? $base . ' ' . $more : $base;
    }

    /**
     * Plain text of the merchant's own words, cut to the allowed length.
     *
     * @param string $extra
     * @return string
     */
    public function cleanExtra(string $extra): string
    {
        $text = strip_tags($extra);
        $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        return mb_substr(trim($text), 0, self::MAX_EXTRA_LENGTH);
    }
}
