# AGENTS.md

## Ground Rules

- **Never, unless specifically instructed by the user** 
  - Commit without explicit permission: Do not run `git commit` (or push, tag, amend, etc.). Staging, diffing, and status checks are fine; committing is not.
  - Alter the Makefile.
- Follow SOLID, DRY, and YAGNI Principles. Follow established design patterns, including those from the Gang of Four. Avoid Anti-patterns.
- Prefer DTO's,and Collections and ArrayObjects over straight array's.
- Avoid implementing deprecated code. Check the deprecation description for hint as to how to implement properly.
- Temporary files for testing, probing, etc. (text output, executable scripts or php files, etc.) should be stored in the `var/temp/` directory.
- For unit testing AND code coverage, use the `make coverage` command.
  - Read test results from the console output.
  - Read coverage results from the `var/coverage/` directory.
- For mutation testing, use the `make mutation` command.
  - Read results from the `var/mutation/` directory.
- For static analysis, use the `make static` command.
- `make agent-test` is the recommended command to run when adding and updating tests.
- For tests:
  - One Test File per covered class
  - One Test Method per public method
  - Use data providers for test cases when tests are mostly repeatable
  - Do NOT create separate tests for code coverage and escape fixes. Add and update existing tests and files as needed.
  - Tests results should not contain any debug artifacts after debugging is complete.
  - Risky Tests count as non-passing tests.
  - Failure Tests count as non-passing tests.
  - Tests with Debugging artifacts count as non-passing tests.
- 

## Sources
- [Discord voice-state fields and retrieval](https://docs.discord.com/developers/resources/voice)
- [Modify Guild Member, including server mute and moving a member between voice channels](https://docs.discord.com/developers/resources/guild#modify-guild-member)
- [Create Guild Channel](https://docs.discord.com/developers/resources/guild#create-guild-channel)
- [Modify Guild Channel Positions](https://docs.discord.com/developers/resources/guild#modify-guild-channel-positions)
- [Add Guild Member Role](https://docs.discord.com/developers/resources/guild#add-guild-member-role)
- [List Guild Members](https://docs.discord.com/developers/resources/guild#list-guild-members)
- [Gateway events, including Guild Member Update and the `pending` field](https://docs.discord.com/developers/events/gateway-events#guild-member-update)
- [Gateway intents, including the privileged GUILD_MEMBERS intent](https://docs.discord.com/developers/events/gateway#privileged-intents)
- [Discord interactions and ephemeral responses](https://docs.discord.com/developers/interactions/receiving-and-responding)
- [Discord permission calculation](https://docs.discord.com/developers/topics/permissions)
- [Discord application commands](https://docs.discord.com/developers/interactions/application-commands)
- [DiscordPHP](https://discord-php.github.io/DiscordPHP.org/)
- [DiscordPHP HTTP routes and rate-limit binding](https://packagist.org/packages/discord-php/http)
- [ReactPHP HTTP cancellation, timeout, and response APIs](https://reactphp.org/http/)
- https://docs.discord.com/developers/bots/overview
- https://docs.discord.com/developers/reference