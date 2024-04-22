﻿# digital-tolk-assignment-refactoring

## Me after Refactoring this code on weekend

![im tired boss](https://media1.tenor.com/m/KBShDXgDMsUAAAAd/green-mile-im-tired-boss.gif)

## Thoughts on the code

Code quality was very poor, readability of the code was also very bad, who ever wrote this code tried to abstract same working and tried
to follow fat model slim controller methodology but did not deliver,
Abstraction (concept) is necessary but abstraction without thought and clear pattern ofter lead to poor code readability.

There were tons of instances where we were querying data from DB inside loops

Assuming you have:

- 100 users
- 5 loops
- Each loop queries the database
  The time complexity would be:
  O(n \* m) = O(100 \* 5) = O(500)
  where n is the number of users (100) and m is the number of loops (5).
  Here's a chart to illustrate the growth of the time complexity:

| Users | Loops | Time Complexity |
| ----- | ----- | --------------- |
| 10    | 1     | O(10)           |
| 10    | 5     | O(50)           |
| 100   | 1     | O(100)          |
| 100   | 5     | O(500)          |
| 1000  | 1     | O(1000)         |
| 1000  | 5     | O(5000)         |

Also variable names wasn't following single pattern. some variable was in snake case other where in camel.

Code was using Repository pattern but only for namesake. moving bad code from controller to repo does not make it better

I have my own patter which i use called office pattern and here's how it works

### Architecture Overview

1. **Controller**: Receives requests and delegates them to managers.
2. **Manager**: Decides which pipeline should handle the request and interacts with the Sculpture service to refine and provide necessary data to workers.
3. **Pipeline**: A sequence of actions that need to be executed for a specific request.
4. **Worker**: Executes actions defined within the pipeline, calls multiple services, and passes data between actions.
5. **Action**: Represents a specific task or operation. Actions can be called by workers, call other actions, and interact with services.
6. **Service Layer**: Manages interactions with external services.
   - **Helper Service**: Provides additional functionalities or utilities.
   - **Notification Service**: Manages sending notifications.
   - **Mailer Service**: Manages sending emails.
   - **Payment Service**: Manages payment processing.


                 +------------------+
                 |     Controller   |  Receives requests and delegates to managers
                 +------------------+
                          |
                          v
                 +------------------+
                 |     Manager      |  Decides which pipeline should handle the request
                 |    +-----------+ |
                 |    |Sculpture  | |  Refines and provides necessary data to workers
                 |    +-----------+ |
                 +------------------+
                          |
                          v
                 +------------------+
                 |     Pipeline     |  A sequence of actions for a specific request
                 +------------------+
                          |
                          v
                 +------------------+
                 |      Worker      |  Executes actions defined within the pipeline
                 +------------------+
                              |
                    +---------+---------+
                    |                   |
                    v                   v
                +----------+        +----------+
                |  Action  |        |  Action  |  Represents specific tasks or operations in database
                +----------+        +----------+  Only Action can talk to DB
                |          |        |          |
                |          |        |          |  Can call services or other actions
                +----------+        +----------+
                      |                   |
                      v                   v
            +----------------+  +----------------+
            | Service Layer  |  | Service Layer  |  Manages interactions with external services
            +----------------+  +----------------+
            | + Helper      |  | + Notification |  Manages sending notifications
            | + Notification|  | + Mailer       |  Manages sending emails
            | + Mailer      |  | + Payment      |  Manages payment processing
            | + Payment     |  |                |
            +----------------+  +----------------+
