<?php

declare(strict_types=1);

namespace icrafts\permissionsexbedrock;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\permission\PermissionAttachment;
use pocketmine\player\Player;
use pocketmine\player\chat\LegacyRawChatFormatter;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use function array_diff;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function count;
use function file_exists;
use function file_put_contents;
use function filesize;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function strtolower;
use function trim;
use function ucfirst;

final class PermissionsExBedrock extends PluginBase implements Listener
{
    private const MSG_PREFIX = "&6[PEX]&r ";
    private const DEFAULT_CHAT_PREFIX = "&7[Игрок] &f";
    private const DEFAULT_PERMISSIONS_YAML = "default-group: default\n\ngroups:\n  default:\n    permissions:\n      - \"pocketmine.command.help\"\n      - \"pocketmine.command.list\"\n      - \"pocketmine.command.tell\"\n      - \"pocketmine.command.me\"\n    inheritance: []\n    prefix: \"&7[Игрок] &f\"\n    suffix: \"\"\n\n  moderator:\n    permissions:\n      - \"pocketmine.command.kick\"\n      - \"pocketmine.command.ban.player\"\n      - \"pocketmine.command.ban.ip\"\n      - \"pocketmine.command.ban.list\"\n      - \"pocketmine.command.unban.player\"\n      - \"pocketmine.command.unban.ip\"\n      - \"pocketmine.command.say\"\n    inheritance:\n      - \"default\"\n    prefix: \"&3[Mod]&r \"\n    suffix: \"\"\n\n  admin:\n    permissions:\n      - \"*\"\n    inheritance:\n      - \"moderator\"\n    prefix: \"&c[Admin]&r \"\n    suffix: \"\"\n\nusers: {}\n";

    private Config $data;

    /** @var array<string, PermissionAttachment> */
    private array $attachments = [];

    public function onEnable(): void
    {
        $this->ensurePermissionsFile();
        $this->data = new Config(
            $this->getDataFolder() . "permissions.yml",
            Config::YAML,
        );
        $this->normalizeData();

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->applyToOnlinePlayers();
        $this->getLogger()->info("PermissionsExBedrock enabled.");
    }

    private function ensurePermissionsFile(): void
    {
        $dataFile = $this->getDataFolder() . "permissions.yml";
        if (is_file($dataFile) && filesize($dataFile) > 0) {
            return;
        }

        $resource = $this->getResource("permissions.yml");
        if ($resource !== null) {
            $this->saveResource("permissions.yml", true);
            return;
        }

        if (!file_exists($this->getDataFolder())) {
            @mkdir($this->getDataFolder(), 0777, true);
        }
        file_put_contents($dataFile, self::DEFAULT_PERMISSIONS_YAML);
    }

    public function onDisable(): void
    {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $this->detachFromPlayer($player);
        }
        $this->saveData();
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void
    {
        $this->applyToPlayer($event->getPlayer());
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void
    {
        $this->detachFromPlayer($event->getPlayer());
    }

    public function onPlayerChat(PlayerChatEvent $event): void
    {
        $player = $event->getPlayer();
        $key = $this->normalizeName($player->getName());
        $userData = $this->getUserData($key);
        $groups = $this->getUserGroups($key);

        $prefix = $this->resolveChatPrefix($userData, $groups);
        $suffix = $this->resolveChatSuffix($userData, $groups);

        $format = "{%0}: {%1}";
        if ($prefix !== "" || $suffix !== "") {
            $format = trim($prefix . "{%0}" . $suffix) . " &7: &r{%1}";
        }

        $event->setFormatter(
            new LegacyRawChatFormatter(TextFormat::colorize($format)),
        );
    }

    public function onCommand(
        CommandSender $sender,
        Command $command,
        string $label,
        array $args,
    ): bool {
        if (strtolower($command->getName()) !== "pex") {
            return false;
        }

        if ($args === []) {
            $this->sendHelp($sender);
            return true;
        }

        $root = strtolower((string) $args[0]);
        return match ($root) {
            "help" => $this->handleHelp($sender),
            "reload" => $this->handleReload($sender),
            "user", "users" => $this->handleUser(
                $sender,
                array_slice($args, 1),
            ),
            "group", "groups" => $this->handleGroup(
                $sender,
                array_slice($args, 1),
            ),
            "list" => $this->handleList($sender, array_slice($args, 1)),
            default => $this->unknownSubcommand($sender),
        };
    }

    private function handleHelp(CommandSender $sender): bool
    {
        $this->sendHelp($sender);
        return true;
    }

    private function handleReload(CommandSender $sender): bool
    {
        if (!$sender->hasPermission("pex.command.reload")) {
            $this->msg($sender, "&cYou do not have permission.");
            return true;
        }
        $this->data->reload();
        $this->normalizeData();
        $this->applyToOnlinePlayers();
        $this->msg($sender, "&aPermissions data reloaded and applied.");
        return true;
    }

    private function handleList(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("pex.command.list")) {
            $this->msg($sender, "&cYou do not have permission.");
            return true;
        }

        $what = strtolower((string) ($args[0] ?? "groups"));
        if ($what === "groups") {
            $groups = array_keys($this->getGroups());
            $this->msg(
                $sender,
                "&aGroups: &e" .
                    ($groups === [] ? "-" : implode(", ", $groups)),
            );
            return true;
        }

        if ($what === "users") {
            $users = array_keys($this->getUsers());
            $this->msg(
                $sender,
                "&aUsers: &e" . ($users === [] ? "-" : implode(", ", $users)),
            );
            return true;
        }

        $this->msg($sender, "&cUsage: &e/pex list <groups|users>");
        return true;
    }

    private function handleUser(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("pex.command.user")) {
            $this->msg($sender, "&cYou do not have permission.");
            return true;
        }
        if (count($args) < 2) {
            $this->msg(
                $sender,
                "&cUsage: &e/pex user <name> <list|group|add|remove>",
            );
            return true;
        }

        $user = $this->normalizeName((string) $args[0]);
        $action = strtolower((string) $args[1]);
        $userData = $this->getUserData($user);

        if ($action === "list") {
            $groups = $this->getUserGroups($user);
            $perms = $this->getUserPermissions($userData);
            $this->msg(
                $sender,
                "&aUser &e{$user}&a groups: &e" .
                    ($groups === [] ? "-" : implode(", ", $groups)),
            );
            $this->msg(
                $sender,
                "&aUser &e{$user}&a perms: &e" .
                    ($perms === [] ? "-" : implode(", ", $perms)),
            );
            return true;
        }

        if ($action === "add" || $action === "remove") {
            $perm = trim((string) ($args[2] ?? ""));
            if ($perm === "") {
                $this->msg(
                    $sender,
                    "&cUsage: &e/pex user <name> {$action} <permission>",
                );
                return true;
            }

            $perms = $this->getUserPermissions($userData);
            if ($action === "add") {
                if (!in_array($perm, $perms, true)) {
                    $perms[] = $perm;
                }
            } else {
                $perms = array_values(array_diff($perms, [$perm]));
            }
            $userData["permissions"] = $perms;
            $this->setUserData($user, $userData);
            $this->saveData();
            $this->applyToIfOnline($user);
            $this->msg($sender, "&aUpdated user &e{$user}&a permissions.");
            return true;
        }

        if ($action === "group") {
            $mode = strtolower((string) ($args[2] ?? ""));
            $group = $this->normalizeName((string) ($args[3] ?? ""));
            if ($mode === "" || $group === "") {
                $this->msg(
                    $sender,
                    "&cUsage: &e/pex user <name> group <set|add|remove> <group>",
                );
                return true;
            }
            if (!$this->groupExists($group)) {
                $this->msg($sender, "&cGroup not found: &e{$group}");
                return true;
            }

            $groups = $this->getUserGroups($user);
            if ($mode === "set") {
                $groups = [$group];
            } elseif ($mode === "add") {
                if (!in_array($group, $groups, true)) {
                    $groups[] = $group;
                }
            } elseif ($mode === "remove") {
                $groups = array_values(array_diff($groups, [$group]));
                if ($groups === []) {
                    $groups = [$this->getDefaultGroup()];
                }
            } else {
                $this->msg(
                    $sender,
                    "&cUsage: &e/pex user <name> group <set|add|remove> <group>",
                );
                return true;
            }

            $userData["groups"] = array_values(
                array_unique(array_map([$this, "normalizeName"], $groups)),
            );
            $this->setUserData($user, $userData);
            $this->saveData();
            $this->applyToIfOnline($user);
            $this->msg($sender, "&aUpdated user &e{$user}&a groups.");
            return true;
        }

        $this->msg($sender, "&cUnknown user action.");
        return true;
    }

    private function handleGroup(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("pex.command.group")) {
            $this->msg($sender, "&cYou do not have permission.");
            return true;
        }
        if (count($args) < 2) {
            $this->msg(
                $sender,
                "&cUsage: &e/pex group <name> <create|delete|list|add|remove|inheritance|prefix|suffix>",
            );
            return true;
        }

        $group = $this->normalizeName((string) $args[0]);
        $action = strtolower((string) $args[1]);

        if ($action === "create") {
            if ($this->groupExists($group)) {
                $this->msg($sender, "&cGroup already exists: &e{$group}");
                return true;
            }
            $groups = $this->getGroups();
            $groups[$group] = [
                "permissions" => [],
                "inheritance" => [],
                "prefix" => "",
                "suffix" => "",
            ];
            $this->setGroups($groups);
            $this->saveData();
            $this->applyToOnlinePlayers();
            $this->msg($sender, "&aGroup created: &e{$group}");
            return true;
        }

        if (!$this->groupExists($group)) {
            $this->msg($sender, "&cGroup not found: &e{$group}");
            return true;
        }

        if ($action === "delete") {
            if ($group === $this->getDefaultGroup()) {
                $this->msg($sender, "&cDefault group cannot be deleted.");
                return true;
            }
            $groups = $this->getGroups();
            unset($groups[$group]);
            foreach ($groups as $name => $data) {
                $inheritance = $this->getGroupInheritance($name);
                $inheritance = array_values(array_diff($inheritance, [$group]));
                $groups[$name]["inheritance"] = $inheritance;
            }
            $this->setGroups($groups);
            $this->saveData();
            $this->applyToOnlinePlayers();
            $this->msg($sender, "&aGroup deleted: &e{$group}");
            return true;
        }

        if ($action === "list") {
            $perms = $this->getGroupPermissions($group);
            $parents = $this->getGroupInheritance($group);
            $prefix = (string) ($this->getGroups()[$group]["prefix"] ?? "");
            $suffix = (string) ($this->getGroups()[$group]["suffix"] ?? "");
            $this->msg(
                $sender,
                "&aGroup &e{$group}&a parents: &e" .
                    ($parents === [] ? "-" : implode(", ", $parents)),
            );
            $this->msg(
                $sender,
                "&aGroup &e{$group}&a perms: &e" .
                    ($perms === [] ? "-" : implode(", ", $perms)),
            );
            $this->msg(
                $sender,
                "&aGroup &e{$group}&a prefix: &e" .
                    ($prefix === "" ? "-" : $prefix),
            );
            $this->msg(
                $sender,
                "&aGroup &e{$group}&a suffix: &e" .
                    ($suffix === "" ? "-" : $suffix),
            );
            return true;
        }

        if ($action === "add" || $action === "remove") {
            $perm = trim((string) ($args[2] ?? ""));
            if ($perm === "") {
                $this->msg(
                    $sender,
                    "&cUsage: &e/pex group <name> {$action} <permission>",
                );
                return true;
            }
            $groups = $this->getGroups();
            $perms = $this->getGroupPermissions($group);
            if ($action === "add") {
                if (!in_array($perm, $perms, true)) {
                    $perms[] = $perm;
                }
            } else {
                $perms = array_values(array_diff($perms, [$perm]));
            }
            $groups[$group]["permissions"] = $perms;
            $this->setGroups($groups);
            $this->saveData();
            $this->applyToOnlinePlayers();
            $this->msg($sender, "&aUpdated group &e{$group}&a permissions.");
            return true;
        }

        if ($action === "inheritance") {
            $mode = strtolower((string) ($args[2] ?? ""));
            $parent = $this->normalizeName((string) ($args[3] ?? ""));
            if ($mode === "" || $parent === "") {
                $this->msg(
                    $sender,
                    "&cUsage: &e/pex group <name> inheritance <add|remove> <parent>",
                );
                return true;
            }
            if (!$this->groupExists($parent)) {
                $this->msg($sender, "&cParent group not found: &e{$parent}");
                return true;
            }
            if ($parent === $group) {
                $this->msg($sender, "&cGroup cannot inherit itself.");
                return true;
            }

            $groups = $this->getGroups();
            $parents = $this->getGroupInheritance($group);
            if ($mode === "add") {
                if (!in_array($parent, $parents, true)) {
                    $parents[] = $parent;
                }
            } elseif ($mode === "remove") {
                $parents = array_values(array_diff($parents, [$parent]));
            } else {
                $this->msg(
                    $sender,
                    "&cUsage: &e/pex group <name> inheritance <add|remove> <parent>",
                );
                return true;
            }
            $groups[$group]["inheritance"] = $parents;
            $this->setGroups($groups);
            $this->saveData();
            $this->applyToOnlinePlayers();
            $this->msg($sender, "&aUpdated group &e{$group}&a inheritance.");
            return true;
        }

        if ($action === "prefix" || $action === "suffix") {
            $mode = strtolower((string) ($args[2] ?? ""));
            if ($mode !== "set") {
                $this->msg(
                    $sender,
                    "&cUsage: &e/pex group <name> {$action} set <text|none>",
                );
                return true;
            }
            $value = trim(implode(" ", array_slice($args, 3)));
            if (strtolower($value) === "none") {
                $value = "";
            }
            $groups = $this->getGroups();
            $groups[$group][$action] = $value;
            $this->setGroups($groups);
            $this->saveData();
            $this->msg($sender, "&aUpdated group &e{$group}&a {$action}.");
            return true;
        }

        $this->msg($sender, "&cUnknown group action.");
        return true;
    }

    private function unknownSubcommand(CommandSender $sender): bool
    {
        $this->msg($sender, "&cUnknown subcommand. Use &e/pex help");
        return true;
    }

    private function sendHelp(CommandSender $sender): void
    {
        $this->msg($sender, "&aPermissionsExBedrock commands:");
        $this->msg($sender, "&e/pex reload");
        $this->msg($sender, "&e/pex list <groups|users>");
        $this->msg($sender, "&e/pex user <name> list");
        $this->msg(
            $sender,
            "&e/pex user <name> group <set|add|remove> <group>",
        );
        $this->msg($sender, "&e/pex user <name> <add|remove> <permission>");
        $this->msg($sender, "&e/pex group <name> create|delete|list");
        $this->msg($sender, "&e/pex group <name> <add|remove> <permission>");
        $this->msg(
            $sender,
            "&e/pex group <name> inheritance <add|remove> <parent>",
        );
        $this->msg(
            $sender,
            "&e/pex group <name> <prefix|suffix> set <text|none>",
        );
    }

    private function normalizeData(): void
    {
        $default = $this->normalizeName(
            (string) $this->data->get("default-group", "default"),
        );
        $groups = $this->data->get("groups", []);
        $users = $this->data->get("users", []);

        if (!is_array($groups)) {
            $groups = [];
        }
        if (!is_array($users)) {
            $users = [];
        }

        if (!isset($groups[$default]) || !is_array($groups[$default])) {
            $groups[$default] = [
                "permissions" => [],
                "inheritance" => [],
                "prefix" => self::DEFAULT_CHAT_PREFIX,
                "suffix" => "",
            ];
        }

        foreach ($groups as $name => $groupData) {
            if (!is_array($groupData)) {
                $groupData = [];
            }
            $groupData["permissions"] = $this->normalizeStringList(
                $groupData["permissions"] ?? [],
            );
            $groupData["inheritance"] = $this->normalizeStringList(
                $groupData["inheritance"] ?? [],
            );
            $groupData["prefix"] = (string) ($groupData["prefix"] ?? "");
            $groupData["suffix"] = (string) ($groupData["suffix"] ?? "");
            $groups[$this->normalizeName((string) $name)] = $groupData;
            if ($this->normalizeName((string) $name) !== (string) $name) {
                unset($groups[$name]);
            }
        }

        foreach ($users as $name => $userData) {
            if (!is_array($userData)) {
                $userData = [];
            }
            $userData["groups"] = $this->normalizeStringList(
                $userData["groups"] ?? [$default],
            );
            if ($userData["groups"] === []) {
                $userData["groups"] = [$default];
            }
            $userData["permissions"] = $this->normalizeStringList(
                $userData["permissions"] ?? [],
            );
            $userData["prefix"] = (string) ($userData["prefix"] ?? "");
            $userData["suffix"] = (string) ($userData["suffix"] ?? "");
            $users[$this->normalizeName((string) $name)] = $userData;
            if ($this->normalizeName((string) $name) !== (string) $name) {
                unset($users[$name]);
            }
        }

        $this->data->set("default-group", $default);
        $this->data->set("groups", $groups);
        $this->data->set("users", $users);
        $this->saveData();
    }

    private function saveData(): void
    {
        $this->data->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function getGroups(): array
    {
        $groups = $this->data->get("groups", []);
        return is_array($groups) ? $groups : [];
    }

    /**
     * @param array<string, mixed> $groups
     */
    private function setGroups(array $groups): void
    {
        $this->data->set("groups", $groups);
    }

    /**
     * @return array<string, mixed>
     */
    private function getUsers(): array
    {
        $users = $this->data->get("users", []);
        return is_array($users) ? $users : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getUserData(string $user): array
    {
        $users = $this->getUsers();
        $data = $users[$user] ?? [];
        if (!is_array($data)) {
            $data = [];
        }
        if (
            !isset($data["groups"]) ||
            !is_array($data["groups"]) ||
            $data["groups"] === []
        ) {
            $data["groups"] = [$this->getDefaultGroup()];
        }
        if (!isset($data["permissions"]) || !is_array($data["permissions"])) {
            $data["permissions"] = [];
        }
        $data["prefix"] = (string) ($data["prefix"] ?? "");
        $data["suffix"] = (string) ($data["suffix"] ?? "");
        return $data;
    }

    /**
     * @param array<string, mixed> $userData
     */
    private function setUserData(string $user, array $userData): void
    {
        $users = $this->getUsers();
        $users[$user] = $userData;
        $this->data->set("users", $users);
    }

    private function getDefaultGroup(): string
    {
        return $this->normalizeName(
            (string) $this->data->get("default-group", "default"),
        );
    }

    private function groupExists(string $group): bool
    {
        return array_key_exists($group, $this->getGroups());
    }

    /**
     * @return string[]
     */
    private function getGroupPermissions(string $group): array
    {
        $groups = $this->getGroups();
        $data = $groups[$group] ?? [];
        return $this->normalizeStringList($data["permissions"] ?? []);
    }

    /**
     * @return string[]
     */
    private function getGroupInheritance(string $group): array
    {
        $groups = $this->getGroups();
        $data = $groups[$group] ?? [];
        return $this->normalizeStringList($data["inheritance"] ?? []);
    }

    /**
     * @return string[]
     */
    private function getUserGroups(string $user): array
    {
        $data = $this->getUserData($user);
        $groups = $this->normalizeStringList($data["groups"] ?? []);
        if ($groups === []) {
            $groups = [$this->getDefaultGroup()];
        }
        return $groups;
    }

    /**
     * @param array<string, mixed> $userData
     * @return string[]
     */
    private function getUserPermissions(array $userData): array
    {
        return $this->normalizeStringList($userData["permissions"] ?? []);
    }

    /**
     * @return string[]
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text !== "") {
                $out[] = $text;
            }
        }
        return array_values(array_unique($out));
    }

    private function applyToOnlinePlayers(): void
    {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $this->applyToPlayer($player);
        }
    }

    private function applyToIfOnline(string $user): void
    {
        $player = $this->getServer()->getPlayerExact($user);
        if ($player !== null) {
            $this->applyToPlayer($player);
            return;
        }
        $player = $this->getServer()->getPlayerByPrefix($user);
        if ($player !== null && strtolower($player->getName()) === $user) {
            $this->applyToPlayer($player);
        }
    }

    private function applyToPlayer(Player $player): void
    {
        $key = $this->normalizeName($player->getName());
        $this->detachFromPlayer($player);

        $permissions = $this->buildEffectivePermissions($key);
        $attachment = $player->addAttachment($this);
        $attachment->setPermissions($permissions);
        $player->recalculatePermissions();
        $this->attachments[$key] = $attachment;
    }

    private function detachFromPlayer(Player $player): void
    {
        $key = $this->normalizeName($player->getName());
        $attachment = $this->attachments[$key] ?? null;
        if ($attachment !== null) {
            $player->removeAttachment($attachment);
            unset($this->attachments[$key]);
        }
    }

    /**
     * @return array<string, bool>
     */
    private function buildEffectivePermissions(string $user): array
    {
        $userData = $this->getUserData($user);
        $groups = $this->getUserGroups($user);
        $map = [];
        $visited = [];

        foreach ($groups as $group) {
            $this->collectGroupPermissions($group, $map, $visited);
        }
        $this->applyPermissionNodes($this->getUserPermissions($userData), $map);
        return $map;
    }

    /**
     * @param array<string, bool> $map
     * @param array<string, bool> $visited
     */
    private function collectGroupPermissions(
        string $group,
        array &$map,
        array &$visited,
    ): void {
        $group = $this->normalizeName($group);
        if (isset($visited[$group])) {
            return;
        }
        $visited[$group] = true;
        if (!$this->groupExists($group)) {
            return;
        }

        foreach ($this->getGroupInheritance($group) as $parent) {
            $this->collectGroupPermissions($parent, $map, $visited);
        }
        $this->applyPermissionNodes($this->getGroupPermissions($group), $map);
    }

    /**
     * @param string[] $nodes
     * @param array<string, bool> $map
     */
    private function applyPermissionNodes(array $nodes, array &$map): void
    {
        foreach ($nodes as $raw) {
            $node = trim($raw);
            if ($node === "") {
                continue;
            }
            if ($node[0] === "-") {
                $name = trim(substr($node, 1));
                if ($name !== "") {
                    $map[$name] = false;
                }
            } else {
                $map[$node] = true;
            }
        }
    }

    private function normalizeName(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * @param array<string, mixed> $userData
     * @param string[] $groups
     */
    private function resolveChatPrefix(array $userData, array $groups): string
    {
        $userPrefix = trim((string) ($userData["prefix"] ?? ""));
        if ($userPrefix !== "") {
            return $userPrefix . " ";
        }

        $parts = [];
        foreach ($groups as $group) {
            $gPrefix = trim(
                (string) ($this->getGroups()[$group]["prefix"] ?? ""),
            );
            if ($gPrefix !== "") {
                $parts[] = $gPrefix;
            }
        }
        if ($parts === []) {
            if ($groups !== []) {
                $firstGroup = (string) $groups[0];
                if ($firstGroup === "default") {
                    return self::DEFAULT_CHAT_PREFIX;
                }
                return "&7[" . ucfirst($firstGroup) . "]&r ";
            }
            return self::DEFAULT_CHAT_PREFIX;
        }
        return implode(" ", array_unique($parts)) . " ";
    }

    /**
     * @param array<string, mixed> $userData
     * @param string[] $groups
     */
    private function resolveChatSuffix(array $userData, array $groups): string
    {
        $userSuffix = trim((string) ($userData["suffix"] ?? ""));
        if ($userSuffix !== "") {
            return " " . $userSuffix;
        }

        $parts = [];
        foreach ($groups as $group) {
            $gSuffix = trim(
                (string) ($this->getGroups()[$group]["suffix"] ?? ""),
            );
            if ($gSuffix !== "") {
                $parts[] = $gSuffix;
            }
        }
        if ($parts === []) {
            return "";
        }
        return " " . implode(" ", array_unique($parts));
    }

    private function msg(CommandSender $sender, string $text): void
    {
        $sender->sendMessage(TextFormat::colorize(self::MSG_PREFIX . $text));
    }
}
