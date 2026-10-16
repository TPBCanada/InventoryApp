<?php foreach ($userSkillData as $user): ?>

    <div class="user-skill-card">

        <h3>
            <?= htmlspecialchars($user['name']) ?>
        </h3>

        <div>
            User ID:
            <?= (int)$user['user_id'] ?>
        </div>

        <div>
            Role:
            <?= htmlspecialchars($user['role']) ?>
        </div>

        <hr>

        <?php foreach ($user['skills'] as $skillKey => $skill): ?>

            <label>
                <input
                    type="checkbox"
                    name="skills[
                        <?= (int)$user['user_id'] ?>
                    ][
                        <?= htmlspecialchars($skillKey) ?>
                    ]"
                    value="1"
                    <?= $skill['enabled'] ? 'checked' : '' ?>
                >

                <?= htmlspecialchars($skill['label']) ?>
            </label>

        <?php endforeach; ?>

    </div>

<?php endforeach; ?>