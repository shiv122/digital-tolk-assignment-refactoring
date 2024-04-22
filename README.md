# digital-tolk-assignment-refactoring

## Me after Refactoring this code on weekend

![im tired boss](https://media1.tenor.com/m/KBShDXgDMsUAAAAd/green-mile-im-tired-boss.gif)

## Thoughts on the code

- **Poor Code Quality and Readability**: The code lacked clarity, making it difficult to understand and maintain. Comments, proper formatting, and consistent coding standards were missing, contributing to its poor quality.

- **Failed Attempt at Abstraction and Fat Model Slim Controller Methodology**: While the code aimed to abstract similar functionalities and adhere to the fat model slim controller methodology, it didn't effectively implement these concepts. For instance, business logic was intertwined with presentation logic, violating the separation of concerns principle.

- **Need for Clear Abstraction Patterns**: Although abstraction is essential for code maintainability and scalability, it must be accompanied by clear design patterns. Without well-defined patterns, the code becomes convoluted and challenging to comprehend, leading to decreased readability.

- **Database Queries Inside Loops**: The code exhibited a common anti-pattern of querying the database within loops. For example, fetching data from the database in a loop iterating over a collection of items. This practice results in poor performance and scalability issues, especially with large datasets.

- **Time Complexity Concerns**: Considering scenarios like 100 users and 5 loops querying the database, the code's time complexity could become a bottleneck. The O(n \* m) complexity (where n is the number of users and m is the number of loops) could lead to significant performance degradation, impacting the application's responsiveness.

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

- **Inconsistent Variable Naming Conventions**: Inconsistencies in variable naming conventions, such as mixing snake case with camel case, further hindered code readability and maintainability. Clear and consistent naming conventions are essential for understanding code quickly.

- **Misuse of Repository Pattern**: Although the code claimed to utilize the Repository pattern, it did not leverage its benefits effectively. Merely moving code from the controller to the repository without proper architectural considerations does not enhance code quality or maintainability.

- **Avoid Nested IF Statements**: The code contained nested IF statements, which can lead to complex control flows and make the code harder to understand and maintain. Refactoring to use techniques like guard clauses, switch statements, or polymorphism can improve readability and maintainability.

# Antipatterns to Avoid in Code

## 1. Spaghetti Code

Spaghetti code is like a messy plate of noodles - poorly organized and tough to untangle. It lacks structure, making it hard to follow. To steer clear of spaghetti code, aim for clear organization, break tasks into smaller pieces, and stick to coding guidelines.

## 2. God Object

The god object is like a superhero with too many powers - it handles everything, but it's overwhelming. This leads to tangled, hard-to-test code. Instead, focus on the single responsibility principle. Divide tasks into smaller, manageable chunks.

## 3. Magic Strings and Numbers

Magic strings and numbers are like hidden secrets in your code - they're hard to understand and prone to errors. Use constants or enums instead. Give them meaningful names so others can easily understand their purpose.

## 5. Nested If Statements

Nested if statements are like a tangled web - they're confusing and easy to get lost in. When you have multiple levels of if statements, the code becomes hard to read and maintain. Instead of nesting ifs deeply, consider refactoring your code to use techniques like guard clauses, switch statements, or polymorphism. This makes your code more readable and easier to understand.

Also i would suggest this article form [medium](https://medium.com/@iamprovidence/is-repository-an-anti-pattern-6aba7422fa48).

### My own Architecture Overview

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
