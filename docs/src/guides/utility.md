# The Utility Page

**Utilities → Cron Guard** lists every command Cron Guard has seen, problems first. The Utilities menu shows how many need a look.

![Utilities → Cron Guard: a failed feed, a missed backup, and commands running as they should](/images/utility.jpg)

| Column | Shows |
|---|---|
| **Command** | As cron ran it, with its arguments. Click it to see its runs. |
| **Status** | OK, Failed, Missed, Stuck, Slow, Learning, No schedule or Muted, with what's wrong. |
| **Runs** | Its schedule, “Learning: 2 of 4 runs”, or Irregular. |
| **Last run** | How long ago it last started. |
| **Usually takes** | Its usual run time. |

**Check now** runs the check straight away and sends anything new.

## A command's runs

Click a command to see its last 100 runs: when each started, how long it took, how it ended with its error message, and its peak memory.

![A command's runs: the latest failed with a timeout, the ones before ran fine](/images/command.jpg)

- **Mute** stops alerts for the command while it's still recorded, such as while you fix it, or for one you don't care about. Mute it for an hour, a day or a week, and it's watched again on its own afterwards, or until you turn it off with **Watch again**. A problem still there when a mute ends is sent then.
- **Forget** removes the command and its runs. It comes back the next time it runs, which is useful after renaming one.

## Dashboard widget

Add the **Cron Guard** widget to the dashboard to see, without opening the utility, whether every command is running as it should. When one isn't, it lists the commands that need a look with what's wrong, each linked to its runs. It also says when cron itself has most likely stopped, how many commands are watched and when one last ran.

## Who can see it

Anyone with permission to use the Cron Guard utility (**Utilities → Cron Guard** under a user group's permissions) can see the page, use its buttons and add the dashboard widget. Sending a test alert needs an admin.
