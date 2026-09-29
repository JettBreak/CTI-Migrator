<?php

namespace App\Entity;

use App\Repository\PasswordHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

/** A password hash a user had before, kept so the last few passwords cannot be chosen again. */
#[ORM\Entity(repositoryClass: PasswordHistoryRepository::class)]
#[ORM\Index(name: 'idx_password_history_user', columns: ['user_id', 'created_at'])]
class PasswordHistory implements RecordsTimezone
{
    use TimezoneColumn;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 255)]
        private string $hash,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getHash(): string
    {
        return $this->hash;
    }
}
