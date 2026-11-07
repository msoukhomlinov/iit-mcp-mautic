<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Service;

use Mautic\CategoryBundle\Entity\Category;
use Mautic\CategoryBundle\Model\CategoryModel;
use Mautic\EmailBundle\Entity\Email;
use Mcp\Exception\ToolCallException;

/**
 * Topics of emails: Mautic categories (bundle "global" or "email"). In the
 * preference center contacts switch topics off; Mautic then skips emails of
 * that category for them (campaigns and segment emails). An email without a
 * topic reaches everyone who did not unsubscribe from all emails.
 */
class EmailTopics
{
    public const NO_TOPIC_WARNING = 'No topic: people who switched topics off in their email preferences still get this email. Set a topic (list_email_topics) unless it is a service email the person asked for (e.g. a download link), sent as transactional.';

    public function __construct(private readonly CategoryModel $categories)
    {
    }

    /** @return list<array{id: int, name: string, description: string|null}> */
    public function all(): array
    {
        $result = $this->categories->getRepository()->createQueryBuilder('c')
            ->where('c.bundle IN (:bundles)')->setParameter('bundles', ['global', 'email'])
            ->andWhere('c.isPublished = 1')
            ->orderBy('c.id', 'ASC')
            ->getQuery()->getResult();

        return array_map(fn (Category $c): array => ['id' => (int) $c->getId(), 'name' => (string) $c->getTitle(), 'description' => $c->getDescription() ?: null], $result);
    }

    /** A topic by ID or name (case-insensitive). */
    public function resolve(string|int $topic): Category
    {
        $wanted = trim((string) $topic);
        foreach ($this->all() as $t) {
            if ((string) $t['id'] === $wanted || 0 === strcasecmp($t['name'], $wanted)) {
                $category = $this->categories->getEntity($t['id']);
                if ($category instanceof Category) {
                    return $category;
                }
            }
        }
        $names = array_map(fn (array $t): string => sprintf('"%s"', $t['name']), $this->all());

        throw new ToolCallException(sprintf('Unknown topic "%s". Topics: %s.', $wanted, [] !== $names ? implode(', ', $names) : 'none yet (they are set up in Mautic as categories)'));
    }

    /** Sets or clears ("" clears) the topic; null leaves it. Returns whether it changed. */
    public function apply(Email $email, string|int|null $topic): bool
    {
        if (null === $topic) {
            return false;
        }
        $category = '' === trim((string) $topic) ? null : $this->resolve($topic);
        if ($email->getCategory()?->getId() === $category?->getId()) {
            return false;
        }
        $email->setCategory($category);

        return true;
    }

    /** @return array{topic: string|null, topic_warning?: string} */
    public function describe(Email $email): array
    {
        $category = $email->getCategory();
        $out      = ['topic' => $category?->getTitle()];
        if (null === $category) {
            $out['topic_warning'] = self::NO_TOPIC_WARNING;
        }

        return $out;
    }
}
