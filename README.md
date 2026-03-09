# PermissionsEx (PEX)

**PermissionsEx** is a powerful, flexible, and ladder-based permission management plugin for **PocketMine-MP**. It allows server administrators to control exactly what players can and cannot do by assigning permissions to specific groups or individuals.

## Features

*   **Group Inheritance:** Create a hierarchy where sub-groups inherit permissions from parent groups (e.g., Mod inherits from Helper).
*   **Rank Ladders:** Easily promote or demote players through defined ranks.
*   **Prefix & Suffix Support:** Custom chat tags for different groups and players.
*   **Timed Permissions:** Grant temporary ranks or permissions that expire automatically.
*   **Multi-Backend:** Support for both YAML (file-based) and MySQL (database) storage.
*   **Per-World Permissions:** Set different permissions for different worlds.

## Commands


| Command | Description | Permission |
|---------|-------------|------------|
| `/pex user <user> group set <group>` | Set a player's group | `permissions.manage` |
| `/pex group <group> add <perm>` | Add permission to a group | `permissions.manage` |
| `/pex promote <user>` | Promote a player to the next rank | `permissions.manage` |
| `/pex reload` | Reload all permissions and config | `permissions.manage` |

## Configuration Example (permissions.yml)

```yaml
groups:
  Guest:
    default: true
    permissions:
    - essentials.spawn
    - lgchat.local
  Admin:
    inheritance:
    - Guest
    permissions:
    - "*"
    options:
      prefix: "§c[Admin] "
