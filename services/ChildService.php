<?php
require_once __DIR__ . '/../repositories/ChildRepository.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../services/score.php';

class ChildService {
    private ChildRepository $repo;

    public function __construct() {
        $this->repo = new ChildRepository();
    }

    public function getAllChildren(): array {
        return $this->repo->findAllWithDetails();
    }

    public function createChild(array $data): array {
        $firstName = trim($data['firstName'] ?? '');
        $lastName  = trim($data['lastName'] ?? '');
        $ageGroup  = $data['ageGroup'] ?? 'GRAND';
        $siblingId = $data['siblingId'] ?? null;
        $parent1Email = trim($data['parent1Email'] ?? '');
        $parent2Email = trim($data['parent2Email'] ?? '');
        $defaultPresences = $data['defaultPresences'] ?? [];

        if (!validate_string($firstName, 1, 100) || !validate_string($lastName, 1, 100)) {
            throw new InvalidArgumentException('Prénom et nom requis');
        }

        if (!in_array($ageGroup, ['PETIT', 'GRAND'])) {
            throw new InvalidArgumentException('Groupe d\'âge invalide');
        }

        $this->repo->beginTransaction();
        try {
            $parentId = null;
            $parent2Id = null;
            $appUrl = rtrim($data['appUrl'] ?? 'http://localhost:5173', '/');
            $parent1Name = trim($data['parent1FirstName'] ?? 'Famille');
            $parent2Name = trim($data['parent2FirstName'] ?? '');

            if ($siblingId) {
                $siblingParents = $this->repo->getParentsBySiblingId($siblingId);
                if ($siblingParents) {
                    $parentId = $siblingParents['parent_id'];
                    $parent2Id = $siblingParents['parent2_id'];
                }
            }

            if (!$parentId && !empty($parent1Email)) {
                $parentId = $this->ensureParentAccount($parent1Email, $parent1Name, $lastName, $appUrl);
            }
            if (!$parent2Id && !empty($parent2Email)) {
                $parent2Id = $this->ensureParentAccount($parent2Email, $parent2Name, $lastName, $appUrl);
            }

            if (!$parentId && !$parent2Id) {
                $this->repo->rollBack();
                throw new InvalidArgumentException('Veuillez fournir au moins un email ou lier à une fratrie');
            }
            
            if (!$parentId) {
                $parentId = $parent2Id;
                $parent2Id = null;
            }

            $childData = [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'parentId' => $parentId,
                'parent2Id' => $parent2Id,
                'ageGroup' => $ageGroup,
                'parent1Name' => $parent1Name,
                'parent2Name' => $parent2Name,
                'parent1Email' => $parent1Email,
                'parent2Email' => $parent2Email,
            ];

            $childId = $this->repo->createChild($childData);

            $createdPresences = [];
            if (!empty($defaultPresences)) {
                $createdPresences = $this->repo->createDefaultPresences($childId, $defaultPresences);
            }

            $this->repo->commit();

            return [
                'id'        => $childId,
                'firstName' => $firstName,
                'lastName'  => $lastName,
                'ageGroup'  => $ageGroup,
                'parentId'  => $parentId,
                'isActive'  => true,
                'createdAt' => date('Y-m-d H:i:s'),
                'parent'    => [
                    'id'        => $parentId,
                    'firstName' => $parent1Name,
                    'lastName'  => $parent2Name,
                ],
                'defaultPresences' => $createdPresences,
            ];
        } catch (Exception $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }

    public function updateChild(string $childId, array $data): array {
        if (!validate_uuid($childId)) {
            throw new InvalidArgumentException('ID invalide');
        }

        $child = $this->repo->findById($childId);
        if (!$child) {
            throw new RuntimeException('Enfant introuvable', 404);
        }

        $this->repo->beginTransaction();
        try {
            $updateData = [
                'firstName' => trim($data['firstName'] ?? $child['first_name']),
                'lastName' => trim($data['lastName'] ?? $child['last_name']),
                'ageGroup' => $data['ageGroup'] ?? $child['age_group'],
                'isActive' => isset($data['isActive']) ? (int) $data['isActive'] : $child['is_active'],
                'parent1FirstName' => trim($data['parent1FirstName'] ?? $child['parent1_first_name'] ?? ''),
                'parent2FirstName' => trim($data['parent2FirstName'] ?? $child['parent2_first_name'] ?? ''),
                'parent1Email' => trim($data['parent1Email'] ?? $child['parent1_email'] ?? ''),
                'parent2Email' => trim($data['parent2Email'] ?? $child['parent2_email'] ?? ''),
            ];

            $this->repo->updateChild($childId, $updateData);
            
            if (isset($data['parent1FirstName']) || isset($data['parent2FirstName']) || isset($data['parent1Email']) || isset($data['parent2Email'])) {
                $e1 = $updateData['parent1Email'];
                $e2 = $updateData['parent2Email'];
                $p1 = $updateData['parent1FirstName'];
                $p2 = $updateData['parent2FirstName'];
                
                $appUrl = rtrim($data['appUrl'] ?? 'http://localhost:5173/planning', '/');

                if (!empty($e1)) {
                    if (!$child['parent_id']) {
                        $newId = $this->ensureParentAccount($e1, $p1, $updateData['lastName'], $appUrl);
                        $this->repo->updateChildParent($childId, 'parent_id', $newId);
                        $child['parent_id'] = $newId;
                    } elseif ($e1 !== $child['parent1_email']) {
                        $this->repo->updateUserEmail($child['parent_id'], $e1);
                    }
                }
                if (!empty($e2)) {
                    if (!$child['parent2_id']) {
                        $newId2 = $this->ensureParentAccount($e2, $p2, $updateData['lastName'], $appUrl);
                        $this->repo->updateChildParent($childId, 'parent2_id', $newId2);
                        $child['parent2_id'] = $newId2;
                    } elseif ($e2 !== $child['parent2_email']) {
                        $this->repo->updateUserEmail($child['parent2_id'], $e2);
                    }
                } elseif (isset($data['parent2Email']) && empty(trim($data['parent2Email'])) && $child['parent2_id']) {
                    $pidToClean = $child['parent2_id'];
                    $this->repo->updateChildParent($childId, 'parent2_id', null);
                    $this->cleanupParentsIfNoChildren(null, $pidToClean);
                }
            }

            if ($child['is_active'] && !$updateData['isActive']) {
                $this->cleanupParentsIfNoChildren($child['parent_id'], $child['parent2_id'] ?? null);
            } elseif (!$child['is_active'] && $updateData['isActive']) {
                if ($child['parent_id']) $this->repo->activateUser($child['parent_id']);
                if ($child['parent2_id']) $this->repo->activateUser($child['parent2_id']);
            }

            $createdPresences = [];
            if (isset($data['defaultPresences'])) {
                $this->repo->deleteDefaultPresences($childId);
                if (!empty($data['defaultPresences'])) {
                    $createdPresences = $this->repo->createDefaultPresences($childId, $data['defaultPresences']);
                }
            } else {
                $createdPresences = $this->repo->getDefaultPresences($childId);
            }

            $parent = $this->repo->findUserById($child['parent_id']);

            $this->repo->commit();

            return [
                'id'        => $childId,
                'firstName' => $updateData['firstName'],
                'lastName'  => $updateData['lastName'],
                'ageGroup'  => $updateData['ageGroup'],
                'parentId'  => $child['parent_id'],
                'isActive'  => (bool) $updateData['isActive'],
                'createdAt' => $child['created_at'],
                'parent'    => [
                    'id'          => $parent['id'] ?? null,
                    'firstName'   => $parent['first_name'] ?? null,
                    'lastName'    => $parent['last_name'] ?? null,
                    'email'       => $parent['email'] ?? '',
                    'secondEmail' => $parent['second_email'] ?? '',
                ],
                'defaultPresences' => $createdPresences,
            ];
        } catch (Exception $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }

    public function deleteChild(string $childId): void {
        if (!validate_uuid($childId)) {
            throw new InvalidArgumentException('ID invalide');
        }

        $child = $this->repo->findById($childId);
        if (!$child) {
            throw new RuntimeException('Enfant introuvable', 404);
        }

        $this->repo->beginTransaction();
        try {
            $this->repo->deleteChild($childId);
            $this->cleanupParentsIfNoChildren($child['parent_id'], $child['parent2_id'] ?? null);
            $this->repo->commit();
        } catch (Exception $e) {
            $this->repo->rollBack();
            throw clone $e; // Rethrow to let controller handle 409
        }
    }

    private function ensureParentAccount(string $email, string $firstName, string $lastName, string $appUrl): string {
        $user = $this->repo->findUserByEmail($email);

        if ($user) {
            if (!$user['is_active']) {
                $this->repo->activateUser($user['id']);
            }
            return $user['id'];
        }

        $userId = $this->repo->createUser([
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName
        ]);

        $token = $this->repo->createPasswordResetToken($email);

        $emailHtml = render_welcome_email($appUrl, $token);
        send_email($email, 'Bienvenue sur Crèche Planning', $emailHtml);

        return $userId;
    }

    private function cleanupParentsIfNoChildren(?string $parentId, ?string $parent2Id): void {
        foreach ([$parentId, $parent2Id] as $pid) {
            if (!$pid) continue;
            
            $activeCount = $this->repo->countActiveChildrenForParent($pid);
            if ($activeCount === 0) {
                $this->repo->deactivateUser($pid);
                
                $totalCount = $this->repo->countTotalChildrenForParent($pid);
                if ($totalCount === 0) {
                    try {
                        $this->repo->deleteUser($pid);
                    } catch (Exception $e) {
                        // Keep soft deleted if foreign key constraint fails
                    }
                }
            }
        }
    }

    public function getAbsences(string $childId): array {
        return $this->repo->findAbsences($childId);
    }

    public function createAbsence(string $childId, array $data): void {
        $data['startDate'] = $data['startDate'] ?? date('Y-m-d');
        $data['startHalfDay'] = $data['startHalfDay'] ?? 'ALL';
        $data['endDate'] = $data['endDate'] ?? null;
        $data['endHalfDay'] = $data['endHalfDay'] ?? 'ALL';
        $data['isConge'] = isset($data['isConge']) ? (int)(bool)$data['isConge'] : 0;

        $this->repo->beginTransaction();
        try {
            $this->repo->createAbsence($childId, $data);
            sync_child_absences_retroactive();
            $this->repo->commit();
        } catch (Exception $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }

    public function updateAbsence(string $childId, string $absenceId, array $data): void {
        $data['startDate'] = $data['startDate'] ?? date('Y-m-d');
        $data['startHalfDay'] = $data['startHalfDay'] ?? 'ALL';
        $data['endDate'] = $data['endDate'] ?? null;
        $data['endHalfDay'] = $data['endHalfDay'] ?? 'ALL';
        $data['isConge'] = isset($data['isConge']) ? (int)(bool)$data['isConge'] : 0;

        $this->repo->beginTransaction();
        try {
            $updated = $this->repo->updateAbsence($childId, $absenceId, $data);
            if ($updated === 0) {
                throw new RuntimeException('Absence introuvable ou aucune modification', 404);
            }
            sync_child_absences_retroactive();
            $this->repo->commit();
        } catch (Exception $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }

    public function deleteAbsence(string $childId, string $absenceId): void {
        $this->repo->beginTransaction();
        try {
            $deleted = $this->repo->deleteAbsence($childId, $absenceId);
            if ($deleted === 0) {
                throw new RuntimeException('Absence introuvable', 404);
            }
            sync_child_absences_retroactive();
            $this->repo->commit();
        } catch (Exception $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }

    public function getHistory(string $childId): array {
        return $this->repo->findHistory($childId);
    }
}
